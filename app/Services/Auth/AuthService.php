<?php

namespace App\Services\Auth;

use App\Enums\ActivityVisibility;
use App\Enums\DeviceApp;
use App\Enums\ErrorCode;
use App\Enums\OtpPurpose;
use App\Enums\PolicyType;
use App\Enums\UserStatus;
use App\Enums\UserType;
use App\Exceptions\ApiException;
use App\Models\User;
use App\Services\Audit\ActivityRecorder;
use App\Services\Settings\PolicyResolver;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Phone + OTP login, client registration and phone change (D3: no passwords).
 * Each app signs in only its own user type; admin staff sign in to the dashboard app (Q9).
 */
class AuthService
{
    public function __construct(
        private readonly OtpService $otp,
        private readonly DeviceSessionService $sessions,
        private readonly ActivityRecorder $activity,
        private readonly PolicyResolver $policies,
    ) {}

    /**
     * @return array{expires_in: int, resend_in: int, length: int}
     */
    public function requestOtp(string $phone, OtpPurpose $purpose, DeviceApp $app): array
    {
        match ($purpose) {
            OtpPurpose::Login => $this->loginUser($phone, $app),
            OtpPurpose::Register => $this->ensureCanRegister($phone, $app),
            OtpPurpose::ChangePhone => throw new ApiException(ErrorCode::Forbidden), // authenticated endpoint only
        };

        return $this->otp->send($phone, $purpose);
    }

    /**
     * @return array{user: User, token: array{access_token: string, token_type: string, expires_in: int}}
     */
    public function login(string $phone, string $code, DeviceApp $app, array $device): array
    {
        $user = $this->loginUser($phone, $app);
        $this->otp->verify($phone, OtpPurpose::Login, $code);

        return DB::transaction(function () use ($user, $app, $device) {
            $user->update(['last_login_at' => now()]);

            return ['user' => $user, 'token' => $this->sessions->issue($user, $app, $device)];
        });
    }

    /**
     * @return array{registration_token: string, expires_in: int}
     */
    public function verifyRegistration(string $phone, string $code, DeviceApp $app): array
    {
        $this->ensureCanRegister($phone, $app);

        return $this->otp->issueRegistrationToken($phone, $code);
    }

    /**
     * @param  array{name: string, email?: ?string, city_id?: ?int}  $profile
     * @return array{user: User, token: array{access_token: string, token_type: string, expires_in: int}}
     */
    public function register(string $registrationToken, array $profile, DeviceApp $app, array $device): array
    {
        if ($app !== DeviceApp::Client) {
            throw new ApiException(ErrorCode::Forbidden);
        }

        return DB::transaction(function () use ($registrationToken, $profile, $app, $device) {
            $phone = $this->otp->consumeRegistrationToken($registrationToken);
            $this->ensurePhoneIsFree($phone);

            $user = User::query()->create([
                'type' => UserType::Client,
                'name' => $profile['name'],
                'phone' => $phone,
                'email' => $profile['email'] ?? null,
                'city_id' => $profile['city_id'] ?? null,
                'locale' => App::getLocale(),
                'status' => UserStatus::Active,
                'last_login_at' => now(),
            ]);

            $this->activity->record($user, 'registered', to: UserStatus::Active, actor: $user, visibility: ActivityVisibility::Internal, meta: [
                'terms_accepted_at' => now()->toIso8601ZuluString(),
                'terms_policy_version_id' => $this->policies->current(PolicyType::Terms)?->id,
            ]);

            return ['user' => $user, 'token' => $this->sessions->issue($user, $app, $device)];
        });
    }

    /**
     * @return array{expires_in: int, resend_in: int, length: int}
     */
    public function requestPhoneChange(User $user, string $newPhone): array
    {
        $this->ensureNewPhone($user, $newPhone);

        return $this->otp->send($newPhone, OtpPurpose::ChangePhone);
    }

    public function changePhone(User $user, string $newPhone, string $code): User
    {
        $this->ensureNewPhone($user, $newPhone);
        $this->otp->verify($newPhone, OtpPurpose::ChangePhone, $code);

        return DB::transaction(function () use ($user, $newPhone) {
            $this->ensurePhoneIsFree($newPhone);

            $oldPhone = $user->phone;
            $user->update(['phone' => $newPhone]);

            $this->activity->record($user, 'phone_changed', actor: $user, visibility: ActivityVisibility::Internal, meta: [
                'old_phone' => $oldPhone,
                'new_phone' => $newPhone,
            ]);

            return $user;
        });
    }

    private function loginUser(string $phone, DeviceApp $app): User
    {
        $user = User::query()->where('phone', $phone)->first()
            ?? throw new ApiException(ErrorCode::AccountNotFound);

        if ($user->type !== $app->userType()) {
            throw new ApiException(ErrorCode::Forbidden);
        }

        if ($user->status === UserStatus::Suspended) {
            throw new ApiException(ErrorCode::AccountSuspended, errors: ['reason' => $user->status_reason]);
        }

        return $user;
    }

    /**
     * Only clients register here; provider accounts are created in Slice 3.
     */
    private function ensureCanRegister(string $phone, DeviceApp $app): void
    {
        if ($app !== DeviceApp::Client) {
            throw new ApiException(ErrorCode::Forbidden);
        }

        $this->ensurePhoneIsFree($phone);
    }

    private function ensureNewPhone(User $user, string $newPhone): void
    {
        if ($newPhone === $user->phone) {
            throw ValidationException::withMessages(['phone' => __('validation.different', [
                'attribute' => __('validation.attributes.phone'),
                'other' => __('validation.attributes.current_phone'),
            ])]);
        }

        $this->ensurePhoneIsFree($newPhone);
    }

    /**
     * Soft-deleted accounts keep their number (the column is unique).
     */
    private function ensurePhoneIsFree(string $phone): void
    {
        if (User::withTrashed()->where('phone', $phone)->exists()) {
            throw ValidationException::withMessages([
                'phone' => __('validation.unique', ['attribute' => __('validation.attributes.phone')]),
            ]);
        }
    }
}
