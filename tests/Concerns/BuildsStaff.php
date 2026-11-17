<?php

namespace Tests\Concerns;

use App\Enums\AdminRole;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

/**
 * Slice 14b: staff members with seeded roles, and the last audit row.
 */
trait BuildsStaff
{
    protected const STAFF_URL = '/api/v1/admin/settings/staff';

    protected const ROLES_URL = '/api/v1/admin/settings/roles';

    protected function seedRoles(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    protected function member(AdminRole|string $role = AdminRole::OperationsSupport, array $attributes = []): User
    {
        if (! Role::query()->exists()) {
            $this->seedRoles();
        }
        $user = User::factory()->admin()->create($attributes);
        $user->assignRole($role instanceof AdminRole ? $role->value : $role);

        return $user;
    }

    protected function superAdmin(array $attributes = []): User
    {
        return $this->member(AdminRole::SuperAdmin, $attributes);
    }

    protected function role(AdminRole|string $role): Role
    {
        return Role::findByName($role instanceof AdminRole ? $role->value : $role, 'api');
    }

    protected function lastAudit(): ?AuditLog
    {
        return AuditLog::query()->latest('id')->first();
    }
}
