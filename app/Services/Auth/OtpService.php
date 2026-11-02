<?php

namespace App\Services\Auth;

use App\Enums\ErrorCode;
use App\Enums\OtpPurpose;
use App\Exceptions\ApiException;
use App\Integrations\Sms\SmsGateway;
use App\Models\OtpCode;
use App\Services\Settings\AppSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * One-time codes sent by SMS. Length, expiry, attempts and resend cooldown come from
 * app_settings; while any of them is not specified the flow answers 409 NOT_CONFIGURED.
 */
class OtpService
{
    public function __construct(
        private readonly AppSettings $settings,
        private readonly SmsGateway $sms,
    ) {}

    /**
     * Sends a new code and replaces any earlier unused code for the same phone and purpose.
     *
     * @return array{expires_in: int, resend_in: int, length: int}
     */
    public function send(string $phone, OtpPurpose $purpose): array
    {
        $length = (int) $this->settings->required('otp.length');
        $expiry = (int) $this->settings->required('otp.expiry_seconds');
        $cooldown = (int) $this->settings->required('otp.resend_cooldown_seconds');

        $code = DB::transaction(function () use ($phone, $purpose, $length, $expiry, $cooldown) {
            $latest = OtpCode::query()
                ->where('phone', $phone)
                ->where('purpose', $purpose)
                ->latest('id')
                ->lockForUpdate()
                ->first();

            $retryAfter = $latest ? $cooldown - (int) $latest->created_at->diffInSeconds(now()) : 0;

            if ($retryAfter > 0) {
                throw new ApiException(ErrorCode::TooManyAttempts, errors: ['retry_after' => $retryAfter]);
            }

            $this->pending($phone, $purpose)->update(['consumed_at' => now()]);

            $code = str_pad((string) random_int(0, 10 ** $length - 1), $length, '0', STR_PAD_LEFT);

            OtpCode::query()->create([
                'phone' => $phone,
                'purpose' => $purpose,
                'code_hash' => Hash::make($code),
                'expires_at' => now()->addSeconds($expiry),
            ]);

            return $code;
        });

        $this->sms->send($phone, __('auth.otp_sms', ['code' => $code]));

        return ['expires_in' => $expiry, 'resend_in' => $cooldown, 'length' => $length];
    }

    /**
     * Checks the code and consumes it. Wrong codes count as attempts; reaching the limit burns the code.
     *
     * @throws ApiException OTP_INVALID, OTP_EXPIRED or TOO_MANY_ATTEMPTS
     */
    public function verify(string $phone, OtpPurpose $purpose, string $code): OtpCode
    {
        $otp = $this->check($phone, $purpose, $code);
        $otp->update(['consumed_at' => now()]);

        return $otp;
    }

    /**
     * Checks a registration code and returns a one-time token for `POST auth/register`.
     *
     * @return array{registration_token: string, expires_in: int}
     */
    public function issueRegistrationToken(string $phone, string $code): array
    {
        $otp = $this->check($phone, OtpPurpose::Register, $code);
        $token = Str::random(64);

        $otp->update(['verified_at' => now(), 'verification_token_hash' => hash('sha256', $token)]);

        return ['registration_token' => $token, 'expires_in' => (int) $this->settings->required('otp.expiry_seconds')];
    }

    /**
     * Uses up a registration token and returns the verified phone. The token is valid for
     * `otp.expiry_seconds` after verification.
     */
    public function consumeRegistrationToken(string $token): string
    {
        $expiry = (int) $this->settings->required('otp.expiry_seconds');

        return DB::transaction(function () use ($token, $expiry) {
            $otp = OtpCode::query()
                ->where('verification_token_hash', hash('sha256', $token))
                ->where('purpose', OtpPurpose::Register)
                ->whereNull('consumed_at')
                ->lockForUpdate()
                ->first();

            if (! $otp) {
                throw new ApiException(ErrorCode::OtpInvalid);
            }

            if ($otp->verified_at->addSeconds($expiry)->isPast()) {
                throw new ApiException(ErrorCode::OtpExpired);
            }

            $otp->update(['consumed_at' => now()]);

            return $otp->phone;
        });
    }

    private function check(string $phone, OtpPurpose $purpose, string $code): OtpCode
    {
        $maxAttempts = (int) $this->settings->required('otp.max_attempts');

        // The attempt counter must be saved even when the check fails, so the outcome is
        // decided inside the transaction and thrown after it commits.
        [$otp, $error] = DB::transaction(function () use ($phone, $purpose, $code, $maxAttempts) {
            $otp = $this->pending($phone, $purpose)->latest('id')->lockForUpdate()->first();

            if (! $otp) {
                return [null, ErrorCode::OtpInvalid];
            }

            if ($otp->expires_at->isPast()) {
                return [$otp, ErrorCode::OtpExpired];
            }

            if (Hash::check($code, $otp->code_hash)) {
                return [$otp, null];
            }

            $otp->attempts++;

            if ($otp->attempts >= $maxAttempts) {
                $otp->consumed_at = now();
                $otp->save();

                return [$otp, ErrorCode::TooManyAttempts];
            }

            $otp->save();

            return [$otp, ErrorCode::OtpInvalid];
        });

        if ($error) {
            throw new ApiException($error);
        }

        return $otp;
    }

    private function pending(string $phone, OtpPurpose $purpose)
    {
        return OtpCode::query()
            ->where('phone', $phone)
            ->where('purpose', $purpose)
            ->whereNull('consumed_at')
            ->whereNull('verified_at');
    }
}
