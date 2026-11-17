<?php

namespace App\Services\Staff;

use App\Enums\AdminRole;
use App\Enums\ErrorCode;
use App\Enums\UserStatus;
use App\Exceptions\ApiException;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Roles and their permission sets (Slice 14b). System roles = the five AdminRole cases; custom roles per D168.
 * super_admin is fixed to every permission (D165); `requests.attachments.view` stays super_admin only (D169).
 */
class RoleManagement
{
    public function __construct(
        private readonly StaffAccess $access,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * System roles first in AdminRole order, then custom roles by name. Each role gets `staff_count`,
     * `updated_at_audit` and `updated_by_name` attributes for the resource.
     *
     * @return Collection<int, Role>
     */
    public function list(): Collection
    {
        $order = array_flip(AdminRole::values());
        $roles = Role::query()->where('guard_name', StaffAccess::GUARD)->with('permissions')->get()
            ->sortBy(fn (Role $r) => [isset($order[$r->name]) ? 0 : 1, $order[$r->name] ?? 0, $r->name])
            ->values();

        return $this->decorate($roles);
    }

    public function find(int $id): Role
    {
        $role = Role::query()->where('guard_name', StaffAccess::GUARD)->with('permissions')->find($id)
            ?? throw new ApiException(ErrorCode::NotFound);

        return $this->decorate(collect([$role]))->first();
    }

    /**
     * @param  list<string>  $permissions
     * @return array{staff_count: int, staff: list<string>, added: list<string>, removed: list<string>}
     */
    public function impact(Role $role, array $permissions): array
    {
        $this->access->ensureNotRestricted($role, $permissions);
        $holders = $this->holders($role);

        return [
            'staff_count' => $holders->count(),
            'staff' => $holders->where('status', UserStatus::Active)->pluck('name')->values()->all(),
            ...$this->access->diff($this->access->rolePermissions($role), $permissions),
        ];
    }

    /**
     * @param  list<string>  $permissions
     */
    public function updatePermissions(User $actor, Role $role, array $permissions, string $reason): Role
    {
        if ($role->isSuperAdmin()) {
            throw new ApiException(ErrorCode::InvalidState, __('errors.role_super_admin_fixed'));
        }
        $this->access->ensureNotRestricted($role, $permissions);

        return DB::transaction(function () use ($actor, $role, $permissions, $reason) {
            $role = Role::query()->lockForUpdate()->find($role->id) ?? throw new ApiException(ErrorCode::NotFound);
            $before = $this->access->rolePermissions($role);
            $diff = $this->access->diff($before, $permissions);
            // D166 on the state seen under the lock (L1).
            $this->access->ensureCanGrant($actor, $diff['added']);
            if ($diff['added'] === [] && $diff['removed'] === []) {
                throw new ApiException(ErrorCode::InvalidState, __('errors.role_unchanged'));
            }
            $role->syncPermissions($permissions);
            $this->forgetPermissionCacheAfterCommit();
            $after = $this->access->rolePermissions($role);
            $this->audit->log('role_permissions_changed', $actor, $role, $reason, ['permissions' => $before], ['permissions' => $after, ...$diff]);

            return $this->find($role->id);
        });
    }

    /**
     * @param  array{ar: string, en: string}  $label
     * @param  list<string>  $permissions
     */
    public function create(User $actor, string $name, array $label, array $permissions, string $reason): Role
    {
        $this->access->ensureNotRestricted(null, $permissions);
        $this->access->ensureCanGrant($actor, $permissions);

        return DB::transaction(function () use ($actor, $name, $label, $permissions, $reason) {
            $role = Role::query()->create(['name' => $name, 'guard_name' => StaffAccess::GUARD, 'label' => $label, 'is_system' => false]);
            $role->syncPermissions($permissions);
            $this->forgetPermissionCacheAfterCommit();
            $this->audit->log('role_created', $actor, $role, $reason, null, ['name' => $name, 'label' => $label, 'permissions' => $this->access->rolePermissions($role)]);

            return $this->find($role->id);
        });
    }

    /**
     * @param  array{ar: string, en: string}  $label
     */
    public function rename(User $actor, Role $role, array $label, string $reason): Role
    {
        $this->ensureCustom($role);

        return DB::transaction(function () use ($actor, $role, $label, $reason) {
            $role = Role::query()->lockForUpdate()->find($role->id) ?? throw new ApiException(ErrorCode::NotFound);
            $old = $role->label;
            $role->update(['label' => $label]);
            $this->audit->log('role_updated', $actor, $role, $reason, ['label' => $old], ['label' => $label]);

            return $this->find($role->id);
        });
    }

    /**
     * Only an unused custom role; the audit row keeps a snapshot because the role row goes (D168).
     */
    public function delete(User $actor, Role $role, string $reason): void
    {
        $this->ensureCustom($role);

        DB::transaction(function () use ($actor, $role, $reason) {
            $role = Role::query()->lockForUpdate()->find($role->id) ?? throw new ApiException(ErrorCode::NotFound);
            if ($this->holderIds($role)->isNotEmpty()) {
                throw new ApiException(ErrorCode::RoleInUse);
            }
            $snapshot = ['name' => $role->name, 'label' => $role->label, 'permissions' => $this->access->rolePermissions($role)];
            $this->audit->log('role_deleted', $actor, $role, $reason, $snapshot, null);
            $role->delete();
            $this->forgetPermissionCacheAfterCommit();
        });
    }

    /**
     * Contract §5.6: role edits take effect on the holders' next request. spatie caches permissions for 24 h, so the
     * cache is cleared once the change is committed (a rollback leaves it as it was).
     */
    private function forgetPermissionCacheAfterCommit(): void
    {
        DB::afterCommit(fn () => app(PermissionRegistrar::class)->forgetCachedPermissions());
    }

    private function ensureCustom(Role $role): void
    {
        if ($role->is_system || AdminRole::tryFrom($role->name) !== null) {
            throw new ApiException(ErrorCode::InvalidState, __('errors.role_system'));
        }
    }

    /**
     * Every user holding the role, suspended and soft-deleted included.
     *
     * @return Collection<int, int>
     */
    private function holderIds(Role $role): Collection
    {
        return DB::table(config('permission.table_names.model_has_roles'))
            ->where('role_id', $role->id)
            ->where('model_type', (new User)->getMorphClass())
            ->pluck('model_id');
    }

    /**
     * @return Collection<int, User>
     */
    private function holders(Role $role): Collection
    {
        return User::query()->whereIn('id', $this->holderIds($role))->orderBy('name')->get(['id', 'name', 'status']);
    }

    /**
     * @param  Collection<int, Role>  $roles
     * @return Collection<int, Role>
     */
    private function decorate(Collection $roles): Collection
    {
        $ids = $roles->pluck('id');
        $counts = DB::table(config('permission.table_names.model_has_roles'))
            ->whereIn('role_id', $ids)
            ->where('model_type', (new User)->getMorphClass())
            ->selectRaw('role_id, count(*) as total')
            ->groupBy('role_id')
            ->pluck('total', 'role_id');
        $latest = AuditLog::query()
            ->with('actor:id,name')
            ->whereIn('id', AuditLog::query()
                ->where('subject_type', (new Role)->getMorphClass())
                ->whereIn('subject_id', $ids)
                ->selectRaw('max(id)')
                ->groupBy('subject_id'))
            ->get()
            ->keyBy('subject_id');

        return $roles->each(function (Role $role) use ($counts, $latest) {
            $role->setAttribute('staff_count', (int) ($counts[$role->id] ?? 0));
            $role->setRelation('latestAudit', $latest[$role->id] ?? null);
        });
    }
}
