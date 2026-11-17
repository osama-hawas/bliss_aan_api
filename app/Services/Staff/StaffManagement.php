<?php

namespace App\Services\Staff;

use App\Enums\ErrorCode;
use App\Enums\UserStatus;
use App\Enums\UserType;
use App\Exceptions\ApiException;
use App\Models\Role;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\DeviceSessionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Staff accounts (Slice 14b): admin users only, one role each (D167). Every write is audit-logged.
 */
class StaffManagement
{
    public const SORTS = ['name', '-name', 'created_at', '-created_at', 'last_login_at', '-last_login_at'];

    public function __construct(
        private readonly StaffAccess $access,
        private readonly AuditLogger $audit,
        private readonly DeviceSessionService $sessions,
    ) {}

    /**
     * @param  array{q: ?string, role: ?string, status: ?string}  $filters
     */
    public function list(array $filters, ?string $sort, int $perPage): LengthAwarePaginator
    {
        $sort ??= '-created_at';

        return User::query()
            ->where('type', UserType::Admin)
            ->with('roles')
            ->when($filters['q'], fn (Builder $q, string $text) => $q->where(fn (Builder $w) => $w
                ->where('name', 'like', "%{$text}%")
                ->orWhere('phone', 'like', '%'.ltrim($text, '+0').'%')))
            ->when($filters['role'], fn (Builder $q, string $role) => $q->whereHas('roles', fn (Builder $r) => $r->where('name', $role)))
            ->when($filters['status'], fn (Builder $q, string $status) => $q->where('status', $status))
            ->orderBy(ltrim($sort, '-'), str_starts_with($sort, '-') ? 'desc' : 'asc')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /**
     * An admin user, or 404 for any other id.
     */
    public function find(int $id): User
    {
        return User::query()->where('type', UserType::Admin)->with('roles')->find($id)
            ?? throw new ApiException(ErrorCode::NotFound);
    }

    /**
     * @param  array{name: string, phone: string, email: ?string}  $data
     */
    public function create(User $actor, array $data, Role $role): User
    {
        return DB::transaction(function () use ($actor, $data, $role) {
            $this->access->lockSuperAdminRole();
            $role = $this->lockRole($role);
            [$actor] = $this->lockUsers($actor);
            $this->ensureCanAssign($actor, $role);

            $staff = User::query()->create([
                'type' => UserType::Admin,
                'name' => $data['name'],
                'phone' => $data['phone'],
                'email' => $data['email'],
                'status' => UserStatus::Active,
            ]);
            $staff->assignRole($role);
            $this->audit->log('staff_created', $actor, $staff, null, null, [
                'name' => $staff->name, 'phone' => $staff->phone, 'email' => $staff->email, 'role' => $role->name,
            ]);

            return $staff->load('roles');
        });
    }

    /**
     * Name and email only; the phone is not editable here (D170).
     *
     * @param  array{name?: string, email?: ?string}  $data
     */
    public function update(User $actor, User $staff, array $data): User
    {
        return DB::transaction(function () use ($actor, $staff, $data) {
            $this->access->lockSuperAdminRole();
            [$actor, $staff] = $this->lockUsers($actor, $staff);
            $this->access->ensureCanManage($actor, $staff);

            $staff->fill($data);
            $changed = array_keys($staff->getDirty());
            if ($changed !== []) {
                $old = array_intersect_key($staff->getOriginal(), array_flip($changed));
                $staff->save();
                $this->audit->log('staff_updated', $actor, $staff, null, $old, $staff->only($changed));
            }

            return $staff->load('roles');
        });
    }

    /**
     * @return array{staff_count: int, staff: list<string>, added: list<string>, removed: list<string>}
     */
    public function roleImpact(User $staff, Role $role): array
    {
        $current = $staff->roles->first();

        return [
            'staff_count' => 1,
            'staff' => [$staff->name],
            ...$this->access->diff($current ? $this->access->rolePermissions($current) : [], $this->access->rolePermissions($role)),
        ];
    }

    public function changeRole(User $actor, User $staff, Role $role, string $reason): User
    {
        $this->access->ensureNotSelf($actor, $staff);

        return DB::transaction(function () use ($actor, $staff, $role, $reason) {
            $this->access->lockSuperAdminRole();
            $role = $this->lockRole($role);
            [$actor, $staff] = $this->lockUsers($actor, $staff);
            $this->access->ensureCanManage($actor, $staff);
            $this->ensureCanAssign($actor, $role);

            $current = $staff->roles()->first();
            if ($current?->is($role)) {
                throw new ApiException(ErrorCode::InvalidState, __('errors.staff_same_role'));
            }
            if (! $role->isSuperAdmin()) {
                $this->access->ensureNotLastSuperAdmin($staff);
            }
            $staff->syncRoles([$role]);
            $this->audit->log('staff_role_changed', $actor, $staff, $reason, ['role' => $current?->name], ['role' => $role->name]);

            return $staff->load('roles');
        });
    }

    /**
     * Disable = suspend (D170): user-visible reason, status log, every session closed. Nothing is deleted.
     */
    public function suspend(User $actor, User $staff, string $reason, ?string $internalNote): User
    {
        $this->access->ensureNotSelf($actor, $staff);

        return DB::transaction(function () use ($actor, $staff, $reason, $internalNote) {
            $this->access->lockSuperAdminRole();
            [$actor, $staff] = $this->lockUsers($actor, $staff);
            $this->access->ensureCanManage($actor, $staff);
            if ($staff->status !== UserStatus::Active) {
                throw new ApiException(ErrorCode::InvalidState, __('errors.staff_already_suspended'));
            }
            $this->access->ensureNotLastSuperAdmin($staff);
            $staff->update(['status' => UserStatus::Suspended, 'status_reason' => $reason]);
            $staff->statusLogs()->create(['action' => 'suspended', 'reason' => $reason, 'internal_note' => $internalNote, 'actor_id' => $actor->id]);
            $this->sessions->revokeAll($staff);
            $this->audit->log('staff_suspended', $actor, $staff, $reason, ['status' => UserStatus::Active->value], ['status' => UserStatus::Suspended->value]);

            return $staff->load('roles');
        });
    }

    public function reactivate(User $actor, User $staff, string $reason, ?string $internalNote): User
    {
        return DB::transaction(function () use ($actor, $staff, $reason, $internalNote) {
            $this->access->lockSuperAdminRole();
            [$actor, $staff] = $this->lockUsers($actor, $staff);
            $this->access->ensureCanManage($actor, $staff);
            if ($staff->status !== UserStatus::Suspended) {
                throw new ApiException(ErrorCode::InvalidState, __('errors.staff_not_suspended'));
            }
            $staff->update(['status' => UserStatus::Active, 'status_reason' => null]);
            $staff->statusLogs()->create(['action' => 'reactivated', 'reason' => $reason, 'internal_note' => $internalNote, 'actor_id' => $actor->id]);
            $this->audit->log('staff_reactivated', $actor, $staff, $reason, ['status' => UserStatus::Suspended->value], ['status' => UserStatus::Active->value]);

            return $staff->load('roles');
        });
    }

    /**
     * Locks the actor (and the target) in id order after the super_admin role row, and re-reads them: D166 is
     * decided on what is committed now, not on what the request saw (M2). An actor suspended meanwhile is refused.
     *
     * @return list<User> [actor] or [actor, target], freshly loaded
     */
    private function lockUsers(User $actor, ?User $target = null): array
    {
        $ids = array_values(array_unique(array_filter([$actor->id, $target?->id])));
        $rows = User::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $freshActor = $rows[$actor->id] ?? throw new ApiException(ErrorCode::Forbidden);
        if ($freshActor->status !== UserStatus::Active) {
            throw new ApiException(ErrorCode::AccountSuspended, errors: ['reason' => $freshActor->status_reason]);
        }
        if ($target === null) {
            return [$freshActor];
        }

        return [$freshActor, $rows[$target->id] ?? throw new ApiException(ErrorCode::NotFound)];
    }

    /**
     * The role being assigned, locked; a role deleted meanwhile is a 404, not a 500 (L2).
     */
    private function lockRole(Role $role): Role
    {
        return Role::query()->lockForUpdate()->find($role->id) ?? throw new ApiException(ErrorCode::NotFound);
    }

    /**
     * D166: only a super admin assigns super_admin; others assign only roles within their own permissions.
     */
    private function ensureCanAssign(User $actor, Role $role): void
    {
        if ($role->isSuperAdmin() && ! $this->access->isSuperAdmin($actor)) {
            throw new ApiException(ErrorCode::Forbidden, __('errors.staff_escalation'));
        }
        $this->access->ensureCanGrant($actor, $this->access->rolePermissions($role));
    }
}
