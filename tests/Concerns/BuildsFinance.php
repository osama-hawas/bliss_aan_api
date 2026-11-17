<?php

namespace Tests\Concerns;

use App\Enums\AdminRole;
use App\Models\Provider;
use App\Models\Role;
use App\Models\User;
use App\Services\Finance\AdjustmentLimits;
use App\Services\Settings\AppSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * Slice 19 helpers. Rows of Slice 10 (`payments`, `refunds`, `payment_webhook_events`) and Slice 11
 * (`provider_ledger_entries`, `payout_requests`) are inserted through their table contracts
 * (slice-10-contract.md §5, slice-11-contract.md §3), so these tests run once S10 and S11 are merged.
 */
trait BuildsFinance
{
    use BuildsStaff;

    protected const FINANCE_URL = '/api/v1/admin/finance';

    protected function financeStaff(array $attributes = []): User
    {
        return $this->member(AdminRole::SuperAdmin, $attributes);
    }

    /**
     * A staff member whose custom role has only the given permissions.
     */
    protected function staffWith(array $permissions): User
    {
        if (! Role::query()->exists()) {
            $this->seedRoles();
        }
        $role = Role::query()->create(['name' => 'finance_'.Str::random(6), 'guard_name' => 'api', 'is_system' => false]);
        $role->givePermissionTo($permissions);
        $user = User::factory()->admin()->create();
        $user->assignRole($role->name);

        return $user;
    }

    protected function setLimits(?string $max, ?string $approval): void
    {
        $settings = app(AppSettings::class);
        $settings->set(AdjustmentLimits::MAX_KEY, $max);
        $settings->set(AdjustmentLimits::APPROVAL_KEY, $approval);
    }

    protected function adjust(User $staff, array $body, ?string $key = null): TestResponse
    {
        return $this->actingAsApi($staff)->withHeader('Accept-Language', 'en')
            ->withHeader('Idempotency-Key', $key ?? (string) Str::uuid())
            ->postJson(self::FINANCE_URL.'/adjustments', $body);
    }

    protected function ledgerEntry(Provider $provider, string $type, int $cents, array $extra = []): int
    {
        static $n = 0;
        $n++;

        return DB::table('provider_ledger_entries')->insertGetId([
            'provider_id' => $provider->id, 'type' => $type, 'amount_cents' => $cents,
            'booking_id' => null, 'payout_request_id' => null, 'gross_cents' => null, 'commission_cents' => null, 'commission_bps' => null,
            'available_at' => now()->subDay(), 'entry_key' => "test:{$type}:{$n}:".Str::random(6), 'meta' => null,
            'created_at' => now(), 'updated_at' => now(), ...$extra,
        ]);
    }

    protected function payoutRequest(Provider $provider, string $status, int $cents, array $extra = []): int
    {
        return DB::table('payout_requests')->insertGetId([
            'reference' => 'PO-'.random_int(1_000_000, 9_999_999), 'provider_id' => $provider->id, 'amount_cents' => $cents, 'status' => $status,
            'holder_name' => 'Noura', 'iban' => encrypt('SA0380000000608010167519'), 'iban_last4' => '7519', 'bank_name_key' => null,
            'transfer_reference' => $status === 'paid' ? 'TR-'.Str::random(8) : null, 'processed_at' => $status === 'requested' ? null : now(),
            'idempotency_key' => (string) Str::uuid(), 'idempotency_fingerprint' => str_repeat('a', 64),
            'open_provider_id' => $status === 'requested' ? $provider->id : null,
            'created_at' => now(), 'updated_at' => now(), ...$extra,
        ]);
    }

    protected function payment(User $payer, string $status, string $amount, array $extra = []): int
    {
        return DB::table('payments')->insertGetId([
            'reference' => 'PY-'.random_int(1_000_000, 9_999_999), 'user_id' => $payer->id, 'payable_type' => 'offer', 'payable_id' => 1,
            'purpose' => 'full', 'amount' => $amount, 'currency' => 'SAR', 'method' => 'card', 'gateway' => 'fake', 'status' => $status,
            'fulfilment_status' => $status === 'succeeded' ? 'fulfilled' : 'not_applicable', 'refunded_amount' => '0.00',
            'idempotency_key' => (string) Str::uuid(), 'idempotency_fingerprint' => str_repeat('b', 64),
            'paid_at' => $status === 'succeeded' ? now() : null, 'expires_at' => now()->addMinutes(30),
            'created_at' => now(), 'updated_at' => now(), ...$extra,
        ]);
    }

    protected function refund(int $paymentId, string $status, string $amount, array $extra = []): int
    {
        return DB::table('refunds')->insertGetId([
            'payment_id' => $paymentId, 'reference' => 'RF-'.random_int(1_000_000, 9_999_999), 'kind' => 'staff', 'amount' => $amount,
            'reason' => 'Test', 'status' => $status, 'idempotency_key' => (string) Str::uuid(),
            'processed_at' => $status === 'in_progress' ? null : now(), 'created_at' => now(), 'updated_at' => now(), ...$extra,
        ]);
    }

    protected function adjustmentEntries(Provider $provider): array
    {
        return DB::table('provider_ledger_entries')->where('provider_id', $provider->id)->where('type', 'adjustment')->get()->all();
    }
}
