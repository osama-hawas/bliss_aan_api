<?php

namespace Database\Seeders;

use App\Enums\AdminRole;
use App\Enums\Permission as PermissionName;
use App\Models\Role;
use App\Services\Staff\RestrictedPermissions;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Admin roles and permissions (docs/02-database-plan.md §10).
 * Role → permission mapping follows the role responsibilities in docs/01 §2; admins adjust it in the dashboard (Slice 14b).
 */
class RolesAndPermissionsSeeder extends Seeder
{
    private const GUARD = 'api';

    /**
     * Non-destructive (D164): creates what is missing and never removes a permission from a role, so role edits made in
     * the dashboard survive a re-seed. A newly created role gets its full default mapping; an existing role only gains
     * the mapping's permissions that were created in this same run (later slices append permissions to the enum and
     * the mapping). `super_admin` always holds every permission (D165). `requests.attachments.view` is never given to
     * any other role (D169, D64).
     */
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $newPermissions = [];
        foreach (PermissionName::cases() as $permission) {
            $existing = Permission::query()->where(['name' => $permission->value, 'guard_name' => self::GUARD])->exists();
            if (! $existing) {
                Permission::findOrCreate($permission->value, self::GUARD);
                $newPermissions[] = $permission->value;
            }
        }

        foreach ($this->rolePermissions() as $name => $permissions) {
            $isNewRole = ! Role::query()->where(['name' => $name, 'guard_name' => self::GUARD])->exists();
            /** @var Role $role */
            $role = Role::findOrCreate($name, self::GUARD);
            $role->forceFill(['is_system' => true])->save();

            $mapped = array_map(fn (PermissionName $p) => $p->value, $permissions);
            if ($name !== AdminRole::SuperAdmin->value) {
                $mapped = array_values(array_diff($mapped, RestrictedPermissions::SUPER_ADMIN_ONLY));
            }
            $grant = $name === AdminRole::SuperAdmin->value || $isNewRole ? $mapped : array_values(array_intersect($mapped, $newPermissions));
            if ($grant !== []) {
                $role->givePermissionTo($grant);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * @return array<string, list<PermissionName>>
     */
    private function rolePermissions(): array
    {
        return [
            AdminRole::SuperAdmin->value => PermissionName::cases(),
            AdminRole::AccountsOfficer->value => [
                PermissionName::DashboardView,
                PermissionName::ClientsView,
                PermissionName::ClientsEdit,
                PermissionName::ClientsSuspend,
                PermissionName::ProvidersView,
                PermissionName::ProvidersReview,
                PermissionName::ProvidersSuspend,
                PermissionName::ProvidersVerify,
            ],
            AdminRole::OperationsSupport->value => [
                PermissionName::DashboardView,
                PermissionName::ClientsView,
                PermissionName::ProvidersView,
                PermissionName::BookingsView,
                PermissionName::BookingsIntervene,
                PermissionName::CancellationsDecide,
                PermissionName::SupportView,
                PermissionName::SupportReply,
                PermissionName::SupportAssign,
                PermissionName::ChatsViewForTicket,
                PermissionName::ReviewsModerate,
            ],
            AdminRole::StoreContentOfficer->value => [
                PermissionName::DashboardView,
                PermissionName::ProductsManage,
                PermissionName::OrdersView,
                PermissionName::OrdersUpdateStatus,
                PermissionName::ContentManage,
            ],
            AdminRole::DeliveryOfficer->value => [
                PermissionName::DeliveryAssigned,
            ],
        ];
    }
}
