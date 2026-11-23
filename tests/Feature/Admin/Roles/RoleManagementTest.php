<?php

namespace Tests\Feature\Admin\Roles;

use App\Enums\AdminRole;
use App\Enums\Permission;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use App\Services\Staff\RoleManagement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\BuildsStaff;
use Tests\TestCase;

/**
 * Slice 14b roles: list, impact, permission edits, custom roles (D168), super_admin fixed (D165),
 * `requests.attachments.view` super_admin only (D169).
 */
class RoleManagementTest extends TestCase
{
    use BuildsStaff, RefreshDatabase;

    private const ATTACHMENTS = 'requests.attachments.view';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->superAdmin(['name' => 'Hessa Admin']);
    }

    private function url(AdminRole|string|Role $role, string $suffix = ''): string
    {
        $id = $role instanceof Role ? $role->id : $this->role($role)->id;

        return self::ROLES_URL.'/'.$id.$suffix;
    }

    public function test_the_list_shows_system_roles_in_order_with_counts(): void
    {
        $this->member(AdminRole::OperationsSupport);
        $this->member(AdminRole::OperationsSupport, ['status' => 'suspended']);

        $response = $this->actingAsApi($this->admin)->getJson(self::ROLES_URL)->assertOk();
        $this->assertSame(AdminRole::values(), array_column($response->json('data'), 'name'));
        $super = $response->json('data.0');
        $this->assertSame(['super_admin', 'Super admin', true, false, [], 1, null], [$super['name'], $super['label'], $super['is_system'], $super['is_editable'], $super['restricted_permissions'], $super['staff_count'], $super['updated_at']]);
        $this->assertCount(count(Permission::cases()), $super['permissions']);
        $ops = $response->json('data.2');
        $this->assertSame([2, true, [self::ATTACHMENTS], null], [$ops['staff_count'], $ops['is_editable'], $ops['restricted_permissions'], $ops['labels']]);

        $this->withHeader('Accept-Language', 'ar')->actingAsApi($this->admin)->getJson($this->url(AdminRole::DeliveryOfficer))
            ->assertOk()->assertJsonPath('data.permissions', ['delivery.assigned']);
    }

    public function test_roles_need_roles_manage(): void
    {
        $ops = $this->member(AdminRole::OperationsSupport);

        $this->actingAsApi($ops)->getJson(self::ROLES_URL)->assertForbidden();
        $this->actingAsApi($ops)->putJson($this->url(AdminRole::DeliveryOfficer), ['permissions' => [], 'reason' => 'No access'])->assertForbidden();
        $this->actingAsApi($this->admin)->getJson(self::ROLES_URL.'/999999')->assertNotFound();
    }

    public function test_impact_then_save_a_permission_set(): void
    {
        $ops = $this->member(AdminRole::DeliveryOfficer, ['name' => 'Reem']);
        $next = ['delivery.assigned', 'orders.view'];

        $this->actingAsApi($this->admin)->postJson($this->url(AdminRole::DeliveryOfficer, '/impact'), ['permissions' => $next])
            ->assertOk()
            ->assertJsonPath('data.staff_count', 1)
            ->assertJsonPath('data.staff', ['Reem'])
            ->assertJsonPath('data.added', ['orders.view'])
            ->assertJsonPath('data.removed', []);
        $this->assertSame(0, AuditLog::query()->count());

        $this->actingAsApi($this->admin)->putJson($this->url(AdminRole::DeliveryOfficer), ['permissions' => $next])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->actingAsApi($this->admin)->putJson($this->url(AdminRole::DeliveryOfficer), ['permissions' => ['nope.perm'], 'reason' => 'Bad name'])->assertUnprocessable();
        $this->actingAsApi($this->admin)->putJson($this->url(AdminRole::DeliveryOfficer), ['permissions' => $next, 'reason' => 'Drivers see orders'])
            ->assertOk()
            ->assertJsonPath('data.permissions', $next)
            ->assertJsonPath('data.updated_by', 'Hessa Admin');

        $audit = $this->lastAudit();
        $this->assertSame('role_permissions_changed', $audit->action);
        $this->assertSame(['delivery.assigned'], $audit->old['permissions']);
        $this->assertSame(['orders.view'], $audit->new['added']);
        // Immediately visible to the holder.
        $this->actingAsApi($ops->fresh())->getJson('/api/v1/auth/me')->assertJsonPath('data.permissions', $next);

        $this->actingAsApi($this->admin)->putJson($this->url(AdminRole::DeliveryOfficer), ['permissions' => $next, 'reason' => 'Same again'])
            ->assertStatus(409)->assertJsonPath('code', 'INVALID_STATE');
    }

    /**
     * M1 (contract §5.6): the spatie permission cache is cleared once the role change is committed, so a cache filled
     * with the old state during the transaction cannot keep a removed permission alive.
     */
    public function test_the_permission_cache_is_cleared_after_commit(): void
    {
        $key = config('permission.cache.key');
        $registrar = app(PermissionRegistrar::class);
        $roles = app(RoleManagement::class);
        $custom = $this->createCustom('cache_probe', ['dashboard.view']);

        foreach ([
            fn () => $roles->updatePermissions($this->admin, $this->role(AdminRole::DeliveryOfficer), ['delivery.assigned', 'orders.view'], 'Cache check'),
            fn () => $roles->create($this->admin, 'cache_new', ['ar' => 'جديد', 'en' => 'New'], ['dashboard.view'], 'Cache check'),
            fn () => $roles->delete($this->admin, $custom, 'Cache check'),
        ] as $change) {
            DB::transaction(function () use ($change, $registrar, $key) {
                $change();
                $registrar->getPermissions(); // another request refills the cache before the commit
                $this->assertTrue(Cache::store(config('permission.cache.store') === 'default' ? null : config('permission.cache.store'))->has($key));
            });
            $this->assertFalse(Cache::store(config('permission.cache.store') === 'default' ? null : config('permission.cache.store'))->has($key));
        }
    }

    public function test_the_super_admin_role_is_fixed(): void
    {
        $this->actingAsApi($this->admin)->putJson($this->url(AdminRole::SuperAdmin), ['permissions' => ['dashboard.view'], 'reason' => 'Trim it'])
            ->assertStatus(409)->assertJsonPath('code', 'INVALID_STATE');
        $this->assertCount(count(Permission::cases()), $this->role(AdminRole::SuperAdmin)->permissions);
    }

    /**
     * D169: requests.attachments.view is refused for every role but super_admin — system or custom, save, create or
     * preview — even when a super admin asks, and nothing is written.
     */
    public function test_the_attachments_permission_stays_super_admin_only(): void
    {
        $custom = $this->createCustom('event_lead', ['bookings.view']);
        $auditCount = AuditLog::query()->count();

        $calls = [
            fn () => $this->actingAsApi($this->admin)->putJson($this->url(AdminRole::OperationsSupport), ['permissions' => [...$this->role(AdminRole::OperationsSupport)->permissions->pluck('name'), self::ATTACHMENTS], 'reason' => 'Need files']),
            fn () => $this->actingAsApi($this->admin)->putJson($this->url($custom), ['permissions' => ['bookings.view', self::ATTACHMENTS], 'reason' => 'Need files']),
            fn () => $this->actingAsApi($this->admin)->postJson(self::ROLES_URL, ['name' => 'file_reader', 'label' => ['ar' => 'قارئة', 'en' => 'Reader'], 'permissions' => [self::ATTACHMENTS], 'reason' => 'Need files']),
            fn () => $this->actingAsApi($this->admin)->postJson($this->url($custom, '/impact'), ['permissions' => [self::ATTACHMENTS]]),
            fn () => $this->actingAsApi($this->admin)->postJson($this->url(AdminRole::AccountsOfficer, '/impact'), ['permissions' => [self::ATTACHMENTS]]),
        ];
        foreach ($calls as $call) {
            $call()->assertUnprocessable()->assertJsonPath('code', 'PERMISSION_RESTRICTED')->assertJsonValidationErrors('permissions');
        }

        $this->assertSame($auditCount, AuditLog::query()->count());
        $this->assertDatabaseMissing('roles', ['name' => 'file_reader']);
        $this->assertSame([AdminRole::SuperAdmin->value], $this->holdersOf(self::ATTACHMENTS));
    }

    public function test_custom_roles_can_be_created_renamed_assigned_and_deleted_when_unused(): void
    {
        $role = $this->createCustom('event_lead', ['bookings.view', 'dashboard.view']);
        $this->assertSame('role_created', $this->lastAudit()->action);

        $this->actingAsApi($this->admin)->postJson(self::ROLES_URL, ['name' => 'event_lead', 'label' => ['ar' => 'مكرر', 'en' => 'Dup'], 'permissions' => [], 'reason' => 'Duplicate'])
            ->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->actingAsApi($this->admin)->postJson(self::ROLES_URL, ['name' => 'Bad Key', 'label' => ['ar' => 'س', 'en' => ''], 'permissions' => [], 'reason' => 'Bad'])
            ->assertUnprocessable()->assertJsonValidationErrors(['name', 'label.ar', 'label.en']);

        $this->withHeader('Accept-Language', 'en')->actingAsApi($this->admin)->patchJson($this->url($role), ['label' => ['ar' => 'قائدة الفعاليات', 'en' => 'Events lead'], 'reason' => 'Clearer name'])
            ->assertOk()->assertJsonPath('data.label', 'Events lead')->assertJsonPath('data.labels.ar', 'قائدة الفعاليات');
        $this->assertSame('role_updated', $this->lastAudit()->action);

        // Assigned → cannot be deleted, even when the holder is suspended.
        $staff = $this->member('event_lead', ['status' => 'suspended']);
        $this->withHeader('Accept-Language', 'ar')->actingAsApi($this->admin)->getJson(self::STAFF_URL.'/'.$staff->id)->assertJsonPath('data.role_label', 'قائدة الفعاليات');
        $this->actingAsApi($this->admin)->deleteJson($this->url($role), ['reason' => 'Not needed'])->assertStatus(409)->assertJsonPath('code', 'ROLE_IN_USE');

        $this->actingAsApi($this->admin)->postJson(self::STAFF_URL.'/'.$staff->id.'/role', ['role' => 'operations_support', 'reason' => 'Moved team'])->assertOk();
        $this->actingAsApi($this->admin)->deleteJson($this->url($role), [])->assertUnprocessable();
        $this->actingAsApi($this->admin)->deleteJson($this->url($role), ['reason' => 'Not needed'])->assertNoContent();
        $this->assertDatabaseMissing('roles', ['id' => $role->id]);
        $audit = $this->lastAudit();
        $this->assertSame(['role_deleted', 'event_lead'], [$audit->action, $audit->old['name']]);
    }

    public function test_system_roles_cannot_be_renamed_or_deleted(): void
    {
        $this->actingAsApi($this->admin)->patchJson($this->url(AdminRole::DeliveryOfficer), ['label' => ['ar' => 'سائقة', 'en' => 'Driver'], 'reason' => 'Rename'])
            ->assertStatus(409)->assertJsonPath('code', 'INVALID_STATE');
        $this->actingAsApi($this->admin)->deleteJson($this->url(AdminRole::DeliveryOfficer), ['reason' => 'Remove'])
            ->assertStatus(409)->assertJsonPath('code', 'INVALID_STATE');
    }

    private function createCustom(string $name, array $permissions): Role
    {
        $this->actingAsApi($this->admin)->postJson(self::ROLES_URL, [
            'name' => $name, 'label' => ['ar' => 'قائدة', 'en' => 'Lead'], 'permissions' => $permissions, 'reason' => 'New team',
        ])->assertCreated()->assertJsonPath('data.is_system', false)->assertJsonPath('data.permissions', collect($permissions)->sort()->values()->all());

        return $this->role($name);
    }

    /**
     * @return list<string>
     */
    private function holdersOf(string $permission): array
    {
        return Role::query()->whereHas('permissions', fn ($q) => $q->where('name', $permission))->pluck('name')->sort()->values()->all();
    }

    public function test_new_role_errors_are_plain_messages(): void
    {
        $body = ['name' => 'مشرفة الحفلات', 'label' => ['ar' => 'مشرفة', 'en' => ''], 'permissions' => [], 'reason' => 'New team'];

        $response = $this->withHeader('Accept-Language', 'ar')->actingAsApi($this->admin)->postJson(self::ROLES_URL, $body)->assertUnprocessable();
        $this->assertStringStartsWith('المعرّف يبدأ بحرف إنجليزي صغير', $response->json('errors.name.0'));
        $this->assertSame('أدخلي اسم الدور بالإنجليزية.', $response->json('errors')['label.en'][0]);
    }
}
