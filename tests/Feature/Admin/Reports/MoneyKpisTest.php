<?php

namespace Tests\Feature\Admin\Reports;

use App\Enums\StoreOrderStatus;
use App\Models\City;
use App\Models\Provider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsCatalog;
use Tests\Concerns\BuildsFinance;
use Tests\Concerns\BuildsStoreOrders;
use Tests\TestCase;

/**
 * Slice 21 (S21) review M2/M3: the money KPI sources over S10 / S9b / S11 rows (inserted by table contract), the revenue
 * dates (`paid_at` / `processed_at`, like S19's FinanceOverview) and the store / finance nav counters.
 */
class MoneyKpisTest extends TestCase
{
    use BuildsCatalog, BuildsFinance, BuildsStoreOrders, RefreshDatabase {
        BuildsFinance::staffWith insteadof BuildsStoreOrders;
    }

    private const URL = '/api/v1/admin/indicators';

    private function kpiGroups(User $staff, string $query = ''): array
    {
        return collect($this->actingAsApi($staff)->getJson(self::URL.$query)->assertOk()->json('data.groups'))->keyBy('key')->all();
    }

    /**
     * A payout request row by S11's table contract (BuildsFinance::payoutRequest still writes the old `bank_name`).
     */
    private function payout(Provider $provider, string $status, int $cents): int
    {
        return DB::table('payout_requests')->insertGetId([
            'reference' => 'PO-'.random_int(1_000_000, 9_999_999), 'provider_id' => $provider->id, 'amount_cents' => $cents, 'status' => $status,
            'holder_name' => 'Noura', 'iban' => encrypt('SA0380000000608010167519'), 'iban_last4' => '7519', 'bank_name_key' => null,
            'transfer_reference' => $status === 'paid' ? 'TR-'.Str::random(8) : null, 'processed_at' => $status === 'requested' ? null : now(),
            'idempotency_key' => (string) Str::uuid(), 'idempotency_fingerprint' => str_repeat('a', 64),
            'open_provider_id' => $status === 'requested' ? $provider->id : null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function item(array $group, string $key): array
    {
        return collect($group['items'])->firstWhere('key', $key);
    }

    public function test_payments_refunds_and_revenue_totals(): void
    {
        $client = User::factory()->client()->create();
        $paid = $this->payment($client, 'succeeded', '1150.00');
        $this->payment($client, 'succeeded', '200.50');
        $this->payment($client, 'failed', '99.00');
        $this->refund($paid, 'refunded', '150.00');
        $this->refund($paid, 'in_progress', '10.00');

        $groups = $this->kpiGroups($this->financeStaff());

        $this->assertSame(2, $this->item($groups['payments'], 'succeeded')['value']);
        $this->assertSame('1350.50', $this->item($groups['payments'], 'succeeded')['amount']['amount']);
        $this->assertSame(1, $this->item($groups['payments'], 'failed')['value']);
        $this->assertSame('150.00', $this->item($groups['refunds'], 'refunded')['amount']['amount']);
        $this->assertSame(1, $this->item($groups['refunds'], 'in_progress')['value']);
        $this->assertSame('1350.50', $this->item($groups['revenue'], 'collected')['amount']['amount']);
        $this->assertSame('150.00', $this->item($groups['revenue'], 'refunded')['amount']['amount']);
        $this->assertSame('1200.50', $this->item($groups['revenue'], 'net')['amount']['amount']);
        $this->assertNull($this->item($groups['revenue'], 'net')['value']);
    }

    public function test_revenue_is_dated_by_paid_at_and_processed_at(): void
    {
        $client = User::factory()->client()->create();
        // Created last month, paid and refunded in the period: counted in the period.
        $payment = $this->payment($client, 'succeeded', '500.00', ['created_at' => '2026-10-20 10:00:00', 'paid_at' => '2026-11-10 10:00:00']);
        $this->refund($payment, 'refunded', '100.00', ['created_at' => '2026-10-25 10:00:00', 'processed_at' => '2026-11-11 10:00:00']);
        // Created in the period but paid after it: not collected in it.
        $this->payment($client, 'succeeded', '70.00', ['created_at' => '2026-11-12 10:00:00', 'paid_at' => '2026-12-02 10:00:00']);
        $staff = $this->financeStaff();

        $groups = $this->kpiGroups($staff, '?filter[from]=2026-11-01&filter[to]=2026-11-30');
        $this->assertSame(1, $this->item($groups['revenue'], 'collected')['value']);
        $this->assertSame('500.00', $this->item($groups['revenue'], 'collected')['amount']['amount']);
        $this->assertSame('100.00', $this->item($groups['revenue'], 'refunded')['amount']['amount']);
        $this->assertSame('400.00', $this->item($groups['revenue'], 'net')['amount']['amount']);

        $this->actingAsApi($staff)->getJson(self::URL.'/revenue/collected/records?filter[from]=2026-11-01&filter[to]=2026-11-30')
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $payment)
            ->assertJsonPath('data.0.href', "/finance/payments/{$payment}");
        $this->actingAsApi($staff)->getJson(self::URL.'/revenue/refunded/records?filter[from]=2026-11-01&filter[to]=2026-11-30')
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.href', "/finance/payments/{$payment}");
        $this->actingAsApi($staff)->getJson(self::URL.'/revenue/net/records')->assertNotFound();
    }

    public function test_payout_totals_per_status(): void
    {
        $provider = $this->provider('Noura Lens');
        $this->payout($provider, 'paid', 120_050);
        $this->payout($this->provider('Huda'), 'requested', 30_000);

        $groups = $this->kpiGroups($this->financeStaff());

        $this->assertSame(1, $this->item($groups['payouts'], 'paid')['value']);
        $this->assertSame('1200.50', $this->item($groups['payouts'], 'paid')['amount']['amount']);
        $this->assertSame('300.00', $this->item($groups['payouts'], 'requested')['amount']['amount']);
    }

    public function test_store_order_totals_and_city_from_the_address_snapshot(): void
    {
        $riyadh = City::query()->create(['name' => ['ar' => 'الرياض', 'en' => 'Riyadh'], 'is_active' => true]);
        $client = User::factory()->client()->create();
        $inRiyadh = $this->bareOrder($client);
        $inRiyadh->forceFill(['total' => '250.00', 'address' => ['city_id' => $riyadh->id, 'city' => ['ar' => 'الرياض', 'en' => 'Riyadh'], 'details' => 'x']])->save();
        $this->bareOrder($client, StoreOrderStatus::Delivered);
        $this->bareOrder($client, StoreOrderStatus::PendingPayment); // not an order yet

        $staff = $this->financeStaff();
        $groups = $this->kpiGroups($staff);
        $this->assertSame(1, $this->item($groups['orders'], 'confirmed')['value']);
        $this->assertSame('250.00', $this->item($groups['orders'], 'confirmed')['amount']['amount']);
        $this->assertSame(1, $this->item($groups['orders'], 'delivered')['value']);
        $this->assertNull(collect($groups['orders']['items'])->firstWhere('key', 'pending_payment'));

        $groups = $this->kpiGroups($staff, '?filter[city_id]='.$riyadh->id);
        $this->assertSame(1, $this->item($groups['orders'], 'confirmed')['value']);
        $this->assertSame(0, $this->item($groups['orders'], 'delivered')['value']);
        $this->actingAsApi($staff)->getJson(self::URL.'/orders/confirmed/records?filter[city_id]='.$riyadh->id)
            ->assertOk()->assertJsonPath('data.0.reference', $inRiyadh->reference)->assertJsonPath('data.0.city.en', 'Riyadh');
    }

    public function test_nav_counts_for_orders_payouts_and_her_deliveries(): void
    {
        $client = User::factory()->client()->create();
        $order = $this->bareOrder($client);
        $this->bareOrder($client, StoreOrderStatus::Preparing);
        $this->payout($this->provider('Noura Lens'), 'requested', 10_000);
        $officer = $this->deliveryOfficer();
        $other = $this->deliveryOfficer();
        $shipment = fn (User $to, string $status, string $method = 'internal') => DB::table('shipments')->insert([
            'store_order_id' => $order->id, 'method' => $method, 'status' => $status, 'assigned_admin_id' => $to->id,
            'request_key' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $shipment($officer, 'pending');
        $shipment($officer, 'in_transit');
        $shipment($officer, 'delivered');
        $shipment($other, 'pending');
        $shipment($officer, 'pending', 'carrier');

        $counts = $this->actingAsApi($this->financeStaff())->getJson('/api/v1/admin/nav-counts')->assertOk()->json('data');
        $this->assertSame(1, $counts['orders']);
        $this->assertSame(1, $counts['payouts']);

        $this->assertSame(['deliveries' => 2], $this->actingAsApi($officer)->getJson('/api/v1/admin/nav-counts')->assertOk()->json('data'));
    }
}
