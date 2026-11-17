<?php

namespace Tests\Feature\Database;

use App\Enums\AdminRole;
use App\Enums\ErrorCode;
use App\Enums\UserStatus;
use App\Exceptions\ApiException;
use App\Models\Role;
use App\Services\Staff\StaffManagement;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsStaff;
use Tests\Concerns\UsesCommittedRows;
use Tests\TestCase;

/**
 * D165 / D166 with two real MySQL sessions (M2). The other session plays a first super admin's change and holds the
 * super_admin role row; this session's action by a second super admin must wait, then decide on what the other session
 * committed: an actor demoted or suspended meanwhile is refused, whatever the target.
 */
class SuperAdminLockingTest extends TestCase
{
    use BuildsStaff, UsesCommittedRows;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCommittedRows();
    }

    protected function tearDown(): void
    {
        $this->tearDownCommittedRows();
        parent::tearDown();
    }

    private function holdSuperAdminRole(): void
    {
        $role = Role::findByName(AdminRole::SuperAdmin->value, 'api');
        DB::connection('other_session')->beginTransaction();
        DB::connection('other_session')->select('select id from roles where id = ? for update', [$role->id]);
    }

    private function assertRefused(ErrorCode $code, callable $call): void
    {
        try {
            $call();
            $this->fail("Expected {$code->value}.");
        } catch (ApiException $e) {
            $this->assertSame($code, $e->errorCode);
        }
    }

    public function test_an_actor_demoted_meanwhile_cannot_act_on_a_super_admin(): void
    {
        $a = $this->superAdmin();
        $b = $this->superAdmin();
        $c = $this->superAdmin();
        $ops = $this->role(AdminRole::OperationsSupport);
        $other = DB::connection('other_session');

        // A demotes B to operations_support in the other session (still holding the role lock).
        $this->holdSuperAdminRole();
        $other->table('model_has_roles')->where('model_id', $b->id)->update(['role_id' => $ops->id]);

        // B, whose request passed the permission check as a super admin, suspends C: waits, then is refused.
        $this->shortLockWait();
        $this->assertWaits(fn () => app(StaffManagement::class)->suspend($b, $c, 'Race', null));
        $other->commit();
        $this->assertRefused(ErrorCode::Forbidden, fn () => app(StaffManagement::class)->suspend($b, $c, 'Race', null));
        $this->assertRefused(ErrorCode::Forbidden, fn () => app(StaffManagement::class)->changeRole($b, $c, $ops, 'Race'));

        $this->assertSame(UserStatus::Active, $c->fresh()->status);
        $this->assertTrue($c->fresh()->hasRole(AdminRole::SuperAdmin->value));
        $this->assertSame(UserStatus::Active, $a->fresh()->status);
    }

    public function test_an_actor_suspended_meanwhile_cannot_act_at_all(): void
    {
        $this->superAdmin();
        $b = $this->superAdmin();
        $staff = $this->member(AdminRole::DeliveryOfficer, ['status' => UserStatus::Suspended, 'status_reason' => 'Leave']);
        $other = DB::connection('other_session');

        $this->holdSuperAdminRole();
        $other->table('users')->where('id', $b->id)->update(['status' => UserStatus::Suspended->value]);

        $this->shortLockWait();
        $this->assertWaits(fn () => app(StaffManagement::class)->reactivate($b, $staff, 'Race', null));
        $other->commit();
        $this->assertRefused(ErrorCode::AccountSuspended, fn () => app(StaffManagement::class)->reactivate($b, $staff, 'Race', null));
        $this->assertSame(UserStatus::Suspended, $staff->fresh()->status);
    }
}
