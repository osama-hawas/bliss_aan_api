<?php

namespace Tests\Feature\Admin\Roles;

use App\Enums\AdminRole;
use App\Enums\Permission as PermissionName;
use App\Models\Role;
use App\Services\Staff\RestrictedPermissions;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * D164: the seeder only adds. Dashboard edits survive a re-seed; permissions new in a run reach their mapped roles once;
 * super_admin always has everything; requests.attachments.view never reaches another role (D169).
 */
class RolesSeederTest extends TestCase
{
    use RefreshDatabase;

    private function role(AdminRole $role): Role
    {
        return Role::findByName($role->value, 'api');
    }

    public function test_seeding_twice_keeps_dashboard_edits(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $delivery = $this->role(AdminRole::DeliveryOfficer);
        $delivery->syncPermissions(['orders.view']); // removed delivery.assigned, added orders.view
        $this->role(AdminRole::OperationsSupport)->revokePermissionTo('reviews.moderate');

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->assertSame(['orders.view'], $delivery->fresh()->permissions->pluck('name')->all());
        $this->assertFalse($this->role(AdminRole::OperationsSupport)->hasPermissionTo('reviews.moderate'));
        $this->assertTrue($this->role(AdminRole::SuperAdmin)->is_system);
        $this->assertCount(count(PermissionName::cases()), $this->role(AdminRole::SuperAdmin)->permissions);
    }

    public function test_a_permission_new_in_a_run_reaches_its_mapped_roles_once(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        // Simulate a later slice's permission: drop it so the next run creates it again.
        Permission::findByName('reviews.moderate', 'api')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->role(AdminRole::OperationsSupport)->revokePermissionTo('chats.view_for_ticket'); // an edit that must stay

        $this->seed(RolesAndPermissionsSeeder::class);

        $ops = $this->role(AdminRole::OperationsSupport);
        $this->assertTrue($ops->hasPermissionTo('reviews.moderate'));
        $this->assertFalse($ops->hasPermissionTo('chats.view_for_ticket'));
        $this->assertTrue($this->role(AdminRole::SuperAdmin)->hasPermissionTo('reviews.moderate'));
        $this->assertFalse($this->role(AdminRole::StoreContentOfficer)->hasPermissionTo('reviews.moderate'));
    }

    public function test_the_attachments_permission_is_mapped_and_seeded_for_super_admin_only(): void
    {
        $mapping = (new ReflectionMethod(RolesAndPermissionsSeeder::class, 'rolePermissions'))->invoke(new RolesAndPermissionsSeeder);
        foreach ($mapping as $role => $permissions) {
            if ($role !== AdminRole::SuperAdmin->value) {
                $this->assertSame([], RestrictedPermissions::in(array_map(fn (PermissionName $p) => $p->value, $permissions)), $role);
            }
        }

        // Even when the permission is new in a run, no other role gets it.
        $this->seed(RolesAndPermissionsSeeder::class);
        Permission::findByName('requests.attachments.view', 'api')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(RolesAndPermissionsSeeder::class);

        $holders = Role::query()->whereHas('permissions', fn ($q) => $q->where('name', 'requests.attachments.view'))->pluck('name')->all();
        $this->assertSame([AdminRole::SuperAdmin->value], $holders);
    }
}
