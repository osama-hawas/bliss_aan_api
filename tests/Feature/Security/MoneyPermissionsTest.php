<?php

namespace Tests\Feature\Security;

use App\Enums\AdminRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsApplications;
use Tests\TestCase;

/**
 * QA round 2026-11-19 (money + roles): only the roles that hold the finance permissions reach the endpoints that move
 * money or reveal bank details. Ids do not need to exist: permission is checked before the record (403, never 404/200).
 */
class MoneyPermissionsTest extends TestCase
{
    use BuildsApplications, RefreshDatabase;

    /** @return list<array{0: string, 1: string}> */
    private function moneyRoutes(): array
    {
        return [
            ['POST', '/api/v1/admin/payments/1/refunds'],
            ['POST', '/api/v1/admin/payouts/1/mark-paid'],
            ['POST', '/api/v1/admin/payouts/1/reject'],
            ['GET', '/api/v1/admin/payouts/1/iban'],
            ['POST', '/api/v1/admin/finance/adjustments'],
            ['POST', '/api/v1/admin/finance/adjustments/1/approve'],
            ['POST', '/api/v1/admin/store-returns/1/refund'],
        ];
    }

    public function test_roles_without_finance_permissions_are_refused(): void
    {
        // "Accounts officer" manages client/provider accounts, not finance (seeder): refused too.
        foreach ([AdminRole::AccountsOfficer, AdminRole::OperationsSupport, AdminRole::StoreContentOfficer, AdminRole::DeliveryOfficer] as $role) {
            $staff = $this->staff($role);
            foreach ($this->moneyRoutes() as [$method, $url]) {
                $status = $this->actingAsApi($staff)->json($method, $url, ['amount' => '10.00', 'reason' => 'x'])->status();
                $this->assertSame(403, $status, "{$role->value} {$method} {$url} answered {$status}");
            }
        }
    }

    public function test_the_super_admin_passes_the_permission_check(): void
    {
        $officer = $this->staff(AdminRole::SuperAdmin);
        foreach ([['POST', '/api/v1/admin/payments/999/refunds'], ['POST', '/api/v1/admin/payouts/999/mark-paid']] as [$method, $url]) {
            $status = $this->actingAsApi($officer)->json($method, $url, ['amount' => '10.00', 'reason' => 'x'])->status();
            $this->assertNotSame(403, $status, "super admin {$method} {$url} was refused");
        }
    }
}
