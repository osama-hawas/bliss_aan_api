<?php

namespace Tests\Feature\Admin\Staff;

use App\Enums\AdminRole;
use App\Enums\DeviceApp;
use App\Enums\ErrorCode;
use App\Enums\UserStatus;
use App\Exceptions\ApiException;
use App\Models\User;
use App\Models\UserDevice;
use App\Services\Auth\DeviceSessionService;
use App\Services\Staff\StaffAccess;
use App\Services\Staff\StaffManagement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsStaff;
use Tests\TestCase;

/**
 * Slice 14b staff accounts: list, create, edit, role change, suspend / reactivate, with the D165–D170 guards.
 */
class StaffManagementTest extends TestCase
{
    use BuildsStaff, RefreshDatabase;

    public function test_the_list_searches_filters_and_sorts(): void
    {
        $admin = $this->superAdmin(['name' => 'Hessa Admin']);
        $ops = $this->member(AdminRole::OperationsSupport, ['name' => 'Noura Ops', 'phone' => '+966511112222']);
        $this->member(AdminRole::DeliveryOfficer, ['name' => 'Reem Delivery'])->update(['status' => UserStatus::Suspended, 'status_reason' => 'x']);
        User::factory()->create(['name' => 'Client Noura']);

        $this->actingAsApi($admin)->getJson(self::STAFF_URL)
            ->assertOk()->assertJsonCount(3, 'data')
            ->assertJsonStructure(['data' => [['id', 'name', 'phone', 'email', 'role', 'role_label', 'status', 'status_label', 'status_reason', 'is_self', 'last_login_at', 'created_at']], 'meta' => ['total']]);
        $this->actingAsApi($admin)->getJson(self::STAFF_URL.'?q=Noura')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $ops->id)
            ->assertJsonPath('data.0.role', 'operations_support')->assertJsonPath('data.0.is_self', false);
        $this->actingAsApi($admin)->getJson(self::STAFF_URL.'?q=0511112222')->assertJsonCount(1, 'data');
        $this->actingAsApi($admin)->getJson(self::STAFF_URL.'?filter[role]=super_admin')->assertJsonCount(1, 'data')->assertJsonPath('data.0.is_self', true);
        $this->actingAsApi($admin)->getJson(self::STAFF_URL.'?filter[status]=suspended')->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Reem Delivery');
        $this->actingAsApi($admin)->getJson(self::STAFF_URL.'?sort=name')->assertJsonPath('data.0.name', 'Hessa Admin');
        $this->actingAsApi($admin)->getJson(self::STAFF_URL.'?sort=phone')->assertUnprocessable();
    }

    public function test_only_admins_with_roles_manage_reach_the_endpoints(): void
    {
        $ops = $this->member(AdminRole::OperationsSupport);
        $client = User::factory()->create();

        $this->actingAsApi($ops)->getJson(self::STAFF_URL)->assertForbidden()->assertJsonPath('code', 'FORBIDDEN');
        $this->actingAsApi($client)->getJson(self::STAFF_URL)->assertForbidden();
    }

    public function test_show_returns_404_for_non_admin_ids_and_includes_status_history(): void
    {
        $admin = $this->superAdmin();
        $client = User::factory()->create();
        $ops = $this->member();

        $this->actingAsApi($admin)->getJson(self::STAFF_URL.'/'.$client->id)->assertNotFound();
        $this->actingAsApi($admin)->postJson(self::STAFF_URL.'/'.$client->id.'/suspend', ['reason' => 'Nope'])->assertNotFound();
        $this->actingAsApi($admin)->postJson(self::STAFF_URL.'/'.$ops->id.'/suspend', ['reason' => 'Contract ended', 'internal_note' => 'HR ticket 12'])->assertOk();
        $this->actingAsApi($admin)->getJson(self::STAFF_URL.'/'.$ops->id)
            ->assertOk()
            ->assertJsonPath('data.status_history.0.action', 'suspended')
            ->assertJsonPath('data.status_history.0.reason', 'Contract ended')
            ->assertJsonPath('data.status_history.0.internal_note', 'HR ticket 12')
            ->assertJsonPath('data.status_history.0.actor', $admin->name);
    }

    public function test_create_normalises_the_phone_and_audits(): void
    {
        $admin = $this->superAdmin();

        $id = $this->actingAsApi($admin)->postJson(self::STAFF_URL, ['name' => 'Sara Store', 'phone' => '0533334444', 'email' => 'sara@example.com', 'role' => 'store_content_officer'])
            ->assertCreated()
            ->assertJsonPath('data.phone', '+966533334444')
            ->assertJsonPath('data.role', 'store_content_officer')
            ->assertJsonPath('data.status', 'active')
            ->json('data.id');

        $staff = User::query()->findOrFail($id);
        $this->assertSame('admin', $staff->type->value);
        $this->assertTrue($staff->hasRole('store_content_officer'));
        $audit = $this->lastAudit();
        $this->assertSame('staff_created', $audit->action);
        $this->assertSame($admin->id, $audit->actor_id);
        $this->assertSame('store_content_officer', $audit->new['role']);
    }

    public function test_create_refuses_a_phone_used_by_any_account_and_bad_input(): void
    {
        $admin = $this->superAdmin();
        User::factory()->create(['phone' => '+966533334444']);
        $gone = User::factory()->create(['phone' => '+966533335555']);
        $gone->delete();

        $this->actingAsApi($admin)->postJson(self::STAFF_URL, ['name' => 'Sara', 'phone' => '0533334444', 'role' => 'store_content_officer'])
            ->assertUnprocessable()->assertJsonValidationErrors('phone');
        $this->actingAsApi($admin)->postJson(self::STAFF_URL, ['name' => 'Sara', 'phone' => '+966533335555', 'role' => 'store_content_officer'])
            ->assertUnprocessable()->assertJsonValidationErrors('phone');
        $this->actingAsApi($admin)->postJson(self::STAFF_URL, ['name' => '', 'phone' => '123', 'role' => 'nope', 'email' => 'x'])
            ->assertUnprocessable()->assertJsonValidationErrors(['name', 'phone', 'role', 'email']);
    }

    public function test_edit_changes_name_and_email_only(): void
    {
        $admin = $this->superAdmin();
        $ops = $this->member(AdminRole::OperationsSupport, ['name' => 'Old', 'phone' => '+966544445555']);

        $this->actingAsApi($admin)->patchJson(self::STAFF_URL.'/'.$ops->id, ['name' => 'New Name', 'email' => 'n@example.com', 'phone' => '+966500009999'])
            ->assertOk()->assertJsonPath('data.name', 'New Name')->assertJsonPath('data.phone', '+966544445555');
        $audit = $this->lastAudit();
        $this->assertSame('staff_updated', $audit->action);
        $this->assertSame(['name' => 'Old', 'email' => $ops->email], $audit->old);
        $this->actingAsApi($admin)->patchJson(self::STAFF_URL.'/'.$ops->id, [])->assertUnprocessable();
        // Own name may be edited (D165 only blocks own role and own suspension).
        $this->actingAsApi($admin)->patchJson(self::STAFF_URL.'/'.$admin->id, ['name' => 'Me'])->assertOk();
    }

    public function test_role_change_previews_the_impact_then_applies_and_audits(): void
    {
        $admin = $this->superAdmin();
        $ops = $this->member(AdminRole::OperationsSupport);

        $this->actingAsApi($admin)->postJson(self::STAFF_URL.'/'.$ops->id.'/role-impact', ['role' => 'store_content_officer'])
            ->assertOk()
            ->assertJsonPath('data.staff_count', 1)
            ->assertJsonPath('data.staff.0', $ops->name)
            ->assertJsonFragment(['added' => ['content.manage', 'orders.update_status', 'orders.view', 'products.manage']]);

        $this->actingAsApi($admin)->postJson(self::STAFF_URL.'/'.$ops->id.'/role', ['role' => 'store_content_officer'])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->actingAsApi($admin)->postJson(self::STAFF_URL.'/'.$ops->id.'/role', ['role' => 'store_content_officer', 'reason' => 'Moved to store'])
            ->assertOk()->assertJsonPath('data.role', 'store_content_officer');
        $audit = $this->lastAudit();
        $this->assertSame(['staff_role_changed', 'Moved to store', ['role' => 'operations_support'], ['role' => 'store_content_officer']], [$audit->action, $audit->reason, $audit->old, $audit->new]);
        $this->assertSame(['store_content_officer'], $ops->fresh()->getRoleNames()->all());

        // Takes effect on her next request.
        $this->actingAsApi($ops->fresh())->getJson('/api/v1/auth/me')->assertJsonPath('data.roles', ['store_content_officer'])
            ->assertJsonFragment(['permissions' => ['content.manage', 'dashboard.view', 'orders.update_status', 'orders.view', 'products.manage']]);

        $this->actingAsApi($admin)->postJson(self::STAFF_URL.'/'.$ops->id.'/role', ['role' => 'store_content_officer', 'reason' => 'Again'])
            ->assertStatus(409)->assertJsonPath('code', 'INVALID_STATE');
    }

    public function test_nobody_changes_her_own_role_or_suspends_herself(): void
    {
        $admin = $this->superAdmin();
        $this->superAdmin();

        $this->actingAsApi($admin)->postJson(self::STAFF_URL.'/'.$admin->id.'/role', ['role' => 'operations_support', 'reason' => 'Self'])
            ->assertStatus(409)->assertJsonPath('code', 'OWN_ACCOUNT');
        $this->actingAsApi($admin)->postJson(self::STAFF_URL.'/'.$admin->id.'/suspend', ['reason' => 'Self'])
            ->assertStatus(409)->assertJsonPath('code', 'OWN_ACCOUNT');
        $this->assertTrue($admin->fresh()->hasRole('super_admin'));
    }

    /**
     * Over HTTP an actor on a super admin is herself another active super admin (D165 self rule + D166, re-checked under
     * the lock, M2), so LAST_SUPER_ADMIN is defence in depth. The guard itself:
     */
    public function test_the_last_active_super_admin_guard(): void
    {
        $last = $this->superAdmin();
        $this->superAdmin(['status' => UserStatus::Suspended]);
        $access = app(StaffAccess::class);

        try {
            $access->ensureNotLastSuperAdmin($last);
            $this->fail('Expected LAST_SUPER_ADMIN');
        } catch (ApiException $e) {
            $this->assertSame(ErrorCode::LastSuperAdmin, $e->errorCode);
        }
        $this->superAdmin();
        $access->ensureNotLastSuperAdmin($last); // two active super admins: allowed
    }

    public function test_an_actor_suspended_meanwhile_is_refused_under_the_lock(): void
    {
        $actor = $this->superAdmin();
        $other = $this->superAdmin();
        $ops = $this->member();
        $actor->update(['status' => UserStatus::Suspended]); // after her request passed the middleware
        $service = app(StaffManagement::class);

        foreach ([
            fn () => $service->suspend($actor, $ops, 'Late action', null),
            fn () => $service->changeRole($actor, $ops, $this->role(AdminRole::DeliveryOfficer), 'Late action'),
            fn () => $service->update($actor, $ops, ['name' => 'Late action']),
            fn () => $service->suspend($actor, $other, 'Late action', null),
        ] as $call) {
            try {
                $call();
                $this->fail('Expected ACCOUNT_SUSPENDED');
            } catch (ApiException $e) {
                $this->assertSame(ErrorCode::AccountSuspended, $e->errorCode);
            }
        }
        $this->assertSame(UserStatus::Active, $ops->fresh()->status);
        $this->assertSame(UserStatus::Active, $other->fresh()->status);
    }

    public function test_a_reason_of_500_characters_is_stored(): void
    {
        $admin = $this->superAdmin();
        $ops = $this->member();
        $reason = str_repeat('س', 500);

        $this->actingAsApi($admin)->postJson(self::STAFF_URL.'/'.$ops->id.'/suspend', ['reason' => $reason])
            ->assertOk()->assertJsonPath('data.status_reason', $reason);
        $this->assertSame($reason, $ops->fresh()->status_reason);
        $this->actingAsApi($admin)->postJson(self::STAFF_URL.'/'.$ops->id.'/reactivate', ['reason' => str_repeat('x', 501)])
            ->assertUnprocessable()->assertJsonValidationErrors('reason');
    }

    public function test_a_role_deleted_meanwhile_is_a_404(): void
    {
        $admin = $this->superAdmin();
        $ops = $this->member();
        $this->actingAsApi($admin)->postJson(self::ROLES_URL, ['name' => 'temp_role', 'label' => ['ar' => 'مؤقت', 'en' => 'Temp'], 'permissions' => [], 'reason' => 'Temporary'])->assertCreated();
        $role = $this->role('temp_role');
        $role->delete(); // between validation and the transaction

        try {
            app(StaffManagement::class)->changeRole($admin, $ops, $role, 'Assign');
            $this->fail('Expected NOT_FOUND');
        } catch (ApiException $e) {
            $this->assertSame(ErrorCode::NotFound, $e->errorCode);
        }
    }

    public function test_a_suspended_super_admin_may_be_demoted_and_two_super_admins_may_swap(): void
    {
        $actor = $this->superAdmin();
        $other = $this->superAdmin();

        $this->actingAsApi($actor)->postJson(self::STAFF_URL.'/'.$other->id.'/suspend', ['reason' => 'Left'])->assertOk();
        $this->actingAsApi($actor)->postJson(self::STAFF_URL.'/'.$other->id.'/role', ['role' => 'delivery_officer', 'reason' => 'Down'])->assertOk();
        $this->actingAsApi($actor)->postJson(self::STAFF_URL.'/'.$other->id.'/reactivate', ['reason' => 'Back'])->assertOk();
        $this->actingAsApi($actor)->postJson(self::STAFF_URL.'/'.$other->id.'/role', ['role' => 'super_admin', 'reason' => 'Promote'])->assertOk();
        $this->actingAsApi($other->fresh())->postJson(self::STAFF_URL.'/'.$actor->id.'/role', ['role' => 'accounts_officer', 'reason' => 'Swap'])->assertOk();
        $this->assertSame(['accounts_officer'], $actor->fresh()->getRoleNames()->all());
    }

    public function test_suspend_closes_every_session_logs_and_reactivate_restores(): void
    {
        $admin = $this->superAdmin();
        $ops = $this->member();
        $token = app(DeviceSessionService::class)->issue($ops, DeviceApp::Dashboard)['access_token'];
        app(DeviceSessionService::class)->issue($ops, DeviceApp::Dashboard);

        $this->actingAsApi($admin)->postJson(self::STAFF_URL.'/'.$ops->id.'/suspend', [])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->actingAsApi($admin)->postJson(self::STAFF_URL.'/'.$ops->id.'/suspend', ['reason' => 'Contract ended'])
            ->assertOk()->assertJsonPath('data.status', 'suspended')->assertJsonPath('data.status_reason', 'Contract ended');

        $this->assertSame(0, UserDevice::query()->where('user_id', $ops->id)->whereNull('revoked_at')->count());
        $this->withApiToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->assertSame('staff_suspended', $this->lastAudit()->action);
        $this->assertDatabaseHas('account_status_logs', ['user_id' => $ops->id, 'action' => 'suspended', 'actor_id' => $admin->id]);
        $this->actingAsApi($admin)->postJson(self::STAFF_URL.'/'.$ops->id.'/suspend', ['reason' => 'Twice'])->assertStatus(409)->assertJsonPath('code', 'INVALID_STATE');

        $this->actingAsApi($admin)->postJson(self::STAFF_URL.'/'.$ops->id.'/reactivate', ['reason' => 'Rehired'])
            ->assertOk()->assertJsonPath('data.status', 'active')->assertJsonPath('data.status_reason', null);
        $this->assertSame('staff_reactivated', $this->lastAudit()->action);
        $this->assertDatabaseHas('account_status_logs', ['user_id' => $ops->id, 'action' => 'reactivated']);
        $this->actingAsApi($admin)->postJson(self::STAFF_URL.'/'.$ops->id.'/reactivate', ['reason' => 'Twice'])->assertStatus(409);
        $this->assertNotNull(User::query()->find($ops->id));
    }

    public function test_a_non_super_admin_manager_cannot_escalate(): void
    {
        $this->seedRoles();
        // A custom role with roles.manage and a few permissions (granted by a super admin).
        $boss = $this->superAdmin();
        $this->actingAsApi($boss)->postJson(self::ROLES_URL, [
            'name' => 'hr_manager', 'label' => ['ar' => 'مديرة الموارد', 'en' => 'HR manager'],
            'permissions' => ['roles.manage', 'dashboard.view', 'clients.view'], 'reason' => 'HR team',
        ])->assertCreated();
        $hr = $this->member('hr_manager');
        $ops = $this->member(AdminRole::OperationsSupport);
        $delivery = $this->member(AdminRole::DeliveryOfficer);

        // Cannot assign super_admin or a role with permissions she lacks, nor touch a super admin.
        $this->actingAsApi($hr)->postJson(self::STAFF_URL.'/'.$delivery->id.'/role', ['role' => 'super_admin', 'reason' => 'Not allowed'])->assertForbidden();
        $this->actingAsApi($hr)->postJson(self::STAFF_URL.'/'.$delivery->id.'/role', ['role' => 'operations_support', 'reason' => 'Not allowed'])->assertForbidden();
        $this->actingAsApi($hr)->postJson(self::STAFF_URL, ['name' => 'Xena', 'phone' => '0566667777', 'role' => 'accounts_officer'])->assertForbidden();
        $this->actingAsApi($hr)->postJson(self::STAFF_URL.'/'.$boss->id.'/suspend', ['reason' => 'Not allowed'])->assertForbidden();
        $this->actingAsApi($hr)->patchJson(self::STAFF_URL.'/'.$boss->id, ['name' => 'Renamed'])->assertForbidden();
        // Granting a permission she lacks through a role edit.
        $this->actingAsApi($hr)->putJson(self::ROLES_URL.'/'.$this->role('hr_manager')->id, ['permissions' => ['roles.manage', 'dashboard.view', 'clients.view', 'finance.view'], 'reason' => 'Not allowed'])->assertForbidden();
        $suspendedSuper = $this->superAdmin(['status' => UserStatus::Suspended, 'status_reason' => 'Leave']);
        $this->actingAsApi($hr)->postJson(self::STAFF_URL.'/'.$suspendedSuper->id.'/reactivate', ['reason' => 'Not allowed'])->assertForbidden();
        $this->assertSame(UserStatus::Suspended, $suspendedSuper->fresh()->status);
        // A custom role with permissions she lacks.
        $this->actingAsApi($hr)->postJson(self::ROLES_URL, ['name' => 'hr_plus', 'label' => ['ar' => 'موارد', 'en' => 'HR plus'], 'permissions' => ['dashboard.view', 'finance.view'], 'reason' => 'Not allowed'])->assertForbidden();
        $this->assertDatabaseMissing('roles', ['name' => 'hr_plus']);
        // Within her own permissions she may act.
        $this->actingAsApi($hr)->postJson(self::STAFF_URL.'/'.$ops->id.'/suspend', ['reason' => 'Allowed now'])->assertOk();
    }
}
