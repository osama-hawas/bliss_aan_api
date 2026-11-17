<?php

namespace App\Console\Commands;

use App\Enums\AdminRole;
use App\Enums\UserStatus;
use App\Enums\UserType;
use App\Models\User;
use App\Support\Phone;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * The first dashboard account on a fresh server (orchestrator decision 2026-11-17): staff sign in with OTP like everyone
 * (D29), so there is no password — only a phone that receives the code. Later staff are added from the dashboard
 * (Settings → Staff, S14b). Idempotent: an existing admin with that phone just gets the super_admin role.
 */
class CreateSuperAdmin extends Command
{
    protected $signature = 'admin:create {phone : Saudi mobile, e.g. 05XXXXXXXX} {name : Display name}';

    protected $description = 'Create (or promote) a super admin who signs in to the dashboard with OTP';

    public function handle(): int
    {
        $phone = Phone::normalizeSaudiMobile((string) $this->argument('phone'));
        if ($phone === null) {
            $this->error('Not a valid Saudi mobile number.');

            return self::FAILURE;
        }

        $user = DB::transaction(function () use ($phone) {
            $user = User::query()->where('phone', $phone)->lockForUpdate()->first();
            if ($user !== null && $user->type !== UserType::Admin) {
                return null;
            }
            $user ??= User::query()->create([
                'phone' => $phone,
                'type' => UserType::Admin,
                'name' => (string) $this->argument('name'),
                'status' => UserStatus::Active,
            ]);
            $user->syncRoles([AdminRole::SuperAdmin->value]);

            return $user;
        });

        if ($user === null) {
            $this->error('That phone belongs to a client or provider account; use another number.');

            return self::FAILURE;
        }

        $this->info("Super admin ready: {$user->name} ({$phone}). Sign in to the dashboard with OTP.");

        return self::SUCCESS;
    }
}
