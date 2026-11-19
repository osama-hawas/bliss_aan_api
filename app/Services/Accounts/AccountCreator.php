<?php

namespace App\Services\Accounts;

use App\Enums\ActivityVisibility;
use App\Enums\ProviderAccountType;
use App\Enums\UserStatus;
use App\Enums\UserType;
use App\Models\AccountStatusLog;
use App\Models\Provider;
use App\Models\User;
use App\Services\Audit\ActivityRecorder;
use App\Services\Audit\AuditLogger;
use App\Services\Providers\ProviderOnboarding;
use Illuminate\Support\Facades\DB;

/**
 * Client and provider accounts added by staff from the dashboard. The account is the same as a self sign-up: she signs
 * in with OTP on that phone. A provider starts as a draft application (D25) she completes and submits for review, so
 * documents and review rules are never skipped.
 */
class AccountCreator
{
    public function __construct(
        private readonly ActivityRecorder $activity,
        private readonly AuditLogger $audit,
        private readonly ProviderOnboarding $onboarding,
    ) {}

    /**
     * @param  array{name: string, phone: string, email: ?string, city_id: ?int}  $profile
     */
    public function createClient(User $admin, array $profile, ?string $reason): User
    {
        return DB::transaction(function () use ($admin, $profile, $reason) {
            $client = $this->createUser(UserType::Client, $profile, $admin);
            AccountStatusLog::query()->create([
                'user_id' => $client->id,
                'action' => 'client_created',
                'reason' => $reason,
                'internal_note' => null,
                'actor_id' => $admin->id,
            ]);
            $this->audit->log('client_created', $admin, $client, $reason, null, $client->only(['name', 'phone', 'email', 'city_id']));

            return $client;
        });
    }

    /**
     * @param  array{name: string, phone: string, email: ?string, city_id: ?int}  $profile
     */
    public function createProvider(User $admin, array $profile, ?ProviderAccountType $accountType, ?string $reason): Provider
    {
        return DB::transaction(function () use ($admin, $profile, $accountType, $reason) {
            $user = $this->createUser(UserType::Provider, $profile, $admin);
            $provider = $this->onboarding->createDraft($user, $admin);
            if ($accountType !== null && $accountType !== $provider->account_type) {
                $provider->update(['account_type' => $accountType]);
            }
            $this->audit->log('provider_created', $admin, $provider, $reason, null, [
                ...$user->only(['name', 'phone', 'email', 'city_id']),
                'account_type' => $provider->account_type->value,
            ]);

            return $provider;
        });
    }

    /**
     * @param  array{name: string, phone: string, email: ?string, city_id: ?int}  $profile
     */
    private function createUser(UserType $type, array $profile, User $admin): User
    {
        $user = User::query()->create([
            'type' => $type,
            'name' => $profile['name'],
            'phone' => $profile['phone'],
            'email' => $profile['email'],
            'city_id' => $profile['city_id'],
            'locale' => config('app.locale'),
            'status' => UserStatus::Active,
        ]);
        $this->activity->record($user, 'registered', to: UserStatus::Active, actor: $admin, visibility: ActivityVisibility::Internal, meta: [
            'created_by_staff' => true,
        ]);

        return $user;
    }
}
