<?php

namespace Database\Seeders;

use App\Enums\AdminRole;
use App\Enums\Permission as PermissionName;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Admin roles and permissions (docs/02-database-plan.md §10).
 * Role → permission mapping follows the role responsibilities in docs/01 §2; admins can adjust it later.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    private const GUARD = 'api';

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (PermissionName::cases() as $permission) {
            Permission::findOrCreate($permission->value, self::GUARD);
        }

        foreach ($this->rolePermissions() as $role => $permissions) {
            Role::findOrCreate($role, self::GUARD)
                ->syncPermissions(array_map(fn (PermissionName $p) => $p->value, $permissions));
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
