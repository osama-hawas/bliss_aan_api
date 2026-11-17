<?php

namespace App\Services\Staff;

use App\Enums\AdminRole;
use App\Enums\ErrorCode;
use App\Enums\UserStatus;
use App\Exceptions\ApiException;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Shared guards for staff and role management: escalation (D166), the restricted attachments permission (D169) and
 * the last-super-admin lock (D165).
 */
class StaffAccess
{
    public const GUARD = 'api';

    public function isSuperAdmin(User $user): bool
    {
        return $user->hasRole(AdminRole::SuperAdmin->value);
    }

    /**
     * @return list<string>
     */
    public function permissionsOf(User $user): array
    {
        return $user->getAllPermissions()->pluck('name')->values()->all();
    }

    /**
     * @return list<string>
     */
    public function rolePermissions(Role $role): array
    {
        return $role->permissions()->pluck('name')->sort()->values()->all();
    }

    /**
     * D166: someone who is not a super admin may only hand out permissions she holds herself.
     *
     * @param  list<string>  $permissions
     */
    public function ensureCanGrant(User $actor, array $permissions): void
    {
        if ($this->isSuperAdmin($actor)) {
            return;
        }
        if (array_diff($permissions, $this->permissionsOf($actor)) !== []) {
            throw new ApiException(ErrorCode::Forbidden, __('errors.staff_escalation'));
        }
    }

    /**
     * D166: only a super admin may act on a staff member who holds the super admin role.
     */
    public function ensureCanManage(User $actor, User $target): void
    {
        if ($this->isSuperAdmin($target) && ! $this->isSuperAdmin($actor)) {
            throw new ApiException(ErrorCode::Forbidden, __('errors.staff_escalation'));
        }
    }

    /**
     * D169: no role but super_admin may hold `requests.attachments.view`, whoever asks.
     *
     * @param  list<string>  $permissions
     */
    public function ensureNotRestricted(?Role $role, array $permissions): void
    {
        if ($role?->isSuperAdmin()) {
            return;
        }
        $restricted = RestrictedPermissions::in($permissions);
        if ($restricted !== []) {
            throw new ApiException(ErrorCode::PermissionRestricted, errors: ['permissions' => [__('errors.PERMISSION_RESTRICTED').' ('.implode(', ', $restricted).')']]);
        }
    }

    /**
     * Locks the super_admin role row. Every change that can reduce the number of active super admins takes this lock
     * first, so two of them cannot both pass the last-super-admin check (D165).
     */
    public function lockSuperAdminRole(): ?Role
    {
        return Role::query()->where(['name' => AdminRole::SuperAdmin->value, 'guard_name' => self::GUARD])->lockForUpdate()->first();
    }

    public function activeSuperAdmins(): int
    {
        return User::query()
            ->whereHas('roles', fn (Builder $q) => $q->where('name', AdminRole::SuperAdmin->value))
            ->where('status', UserStatus::Active)
            ->count();
    }

    public function ensureNotLastSuperAdmin(User $target): void
    {
        if ($target->status === UserStatus::Active && $this->isSuperAdmin($target) && $this->activeSuperAdmins() <= 1) {
            throw new ApiException(ErrorCode::LastSuperAdmin);
        }
    }

    public function ensureNotSelf(User $actor, User $target): void
    {
        if ($actor->is($target)) {
            throw new ApiException(ErrorCode::OwnAccount);
        }
    }

    /**
     * @param  list<string>  $from
     * @param  list<string>  $to
     * @return array{added: list<string>, removed: list<string>}
     */
    public function diff(array $from, array $to): array
    {
        $added = array_values(array_diff($to, $from));
        $removed = array_values(array_diff($from, $to));
        sort($added);
        sort($removed);

        return ['added' => $added, 'removed' => $removed];
    }
}
