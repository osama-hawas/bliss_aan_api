<?php

namespace Tests\Feature\Finance;

use App\Models\Provider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsCatalog;
use Tests\Concerns\BuildsFinance;
use Tests\TestCase;

/**
 * Slice 19 §5 (D294, D295): overview, provider balances, ledger entries across providers. Run after the S10/S11 merge.
 */
class FinanceReadModelsTest extends TestCase
{
    use BuildsCatalog, BuildsFinance, RefreshDatabase;

    private Provider $noura;

    private Provider $huda;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-12-15T09:00:00Z'));
        $this->noura = $this->provider('Noura Lens');
        $this->huda = $this->provider('Huda Studio');
        $this->provider('No Money Yet');
        $this->staff = $this->staffWith(['finance.view']);
    }

    private function seedLedger(): void
    {
        // Noura: 850 available earning, 425 pending earning, 300 payout requested, 200 paid out, 50 manual credit.
        $this->ledgerEntry($this->noura, 'earning', 85000, ['gross_cents' => 100000, 'commission_cents' => 15000, 'commission_bps' => 1500]);
        $this->ledgerEntry($this->noura, 'earning', 42500, ['gross_cents' => 50000, 'commission_cents' => 7500, 'commission_bps' => 1500, 'available_at' => now()->addDays(5)]);
        $requested = $this->payoutRequest($this->noura, 'requested', 30000);
        $this->ledgerEntry($this->noura, 'payout', -30000, ['payout_request_id' => $requested]);
        $paid = $this->payoutRequest($this->noura, 'paid', 20000);
        $this->ledgerEntry($this->noura, 'payout', -20000, ['payout_request_id' => $paid]);
        $this->ledgerEntry($this->noura, 'adjustment', 5000);
        // Huda: refunded after payout → negative.
        $this->ledgerEntry($this->huda, 'refund_adjustment', -8500, ['gross_cents' => -10000, 'commission_cents' => -1500, 'commission_bps' => 1500]);
    }

    public function test_provider_balances_list_filters_and_sorts(): void
    {
        $this->seedLedger();

        $res = $this->actingAsApi($this->staff)->getJson(self::FINANCE_URL.'/provider-balances')->assertOk()->assertJsonCount(2, 'data');
        $this->assertSame('Noura Lens', $res->json('data.0.provider.display_name')); // default -available
        $res->assertJsonPath('data.0.available.amount', '400.00') // 850 earning − 300 − 200 payout debits + 50 credit
            ->assertJsonPath('data.0.pending.amount', '425.00')
            ->assertJsonPath('data.0.in_payout.amount', '300.00')
            ->assertJsonPath('data.0.paid_out.amount', '200.00')
            ->assertJsonPath('data.0.adjustments_net.amount', '50.00')
            ->assertJsonPath('data.1.is_negative', true);

        $this->actingAsApi($this->staff)->getJson(self::FINANCE_URL.'/provider-balances?filter[balance]=negative')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.provider.id', $this->huda->id);
        $this->actingAsApi($this->staff)->getJson(self::FINANCE_URL.'/provider-balances?q=Huda')->assertOk()->assertJsonCount(1, 'data');
        $this->actingAsApi($this->staff)->getJson(self::FINANCE_URL.'/provider-balances?sort=name')->assertOk()->assertJsonPath('data.0.provider.display_name', 'Huda Studio');
        $this->actingAsApi($this->staff)->getJson(self::FINANCE_URL.'/provider-balances?sort=bogus')->assertStatus(422);
    }

    public function test_provider_balance_detail_and_404(): void
    {
        $this->seedLedger();

        $this->actingAsApi($this->staff)->getJson(self::FINANCE_URL."/provider-balances/{$this->noura->id}")->assertOk()
            ->assertJsonPath('data.lifetime.gross.amount', '1500.00')
            ->assertJsonPath('data.lifetime.commission.amount', '225.00')
            ->assertJsonPath('data.lifetime.net.amount', '1275.00')
            ->assertJsonPath('data.recent_adjustments', []);
        $this->actingAsApi($this->staff)->getJson(self::FINANCE_URL.'/provider-balances/999999')->assertNotFound();
    }

    public function test_ledger_entries_across_providers(): void
    {
        $this->seedLedger();

        $this->actingAsApi($this->staff)->getJson(self::FINANCE_URL.'/ledger-entries')->assertOk()->assertJsonCount(6, 'data'); // Noura 5 + Huda 1
        $this->actingAsApi($this->staff)->getJson(self::FINANCE_URL.'/ledger-entries?filter[type]=payout')->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.type_label', 'Payout');
        $this->actingAsApi($this->staff)->getJson(self::FINANCE_URL."/ledger-entries?filter[provider_id]={$this->huda->id}")->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.commission_percent', '15.00');
        $this->actingAsApi($this->staff)->getJson(self::FINANCE_URL.'/ledger-entries?sort=amount')->assertOk()->assertJsonPath('data.0.amount.amount', '-300.00');
        $this->actingAsApi($this->staff)->getJson(self::FINANCE_URL.'/ledger-entries?filter[type]=commission')->assertStatus(422);
    }

    public function test_overview_totals_for_the_period(): void
    {
        $this->seedLedger();
        $client = User::factory()->client()->create();
        $p1 = $this->payment($client, 'succeeded', '1000.00');
        $this->payment($client, 'succeeded', '250.50', ['payable_type' => 'store_order']);
        $this->payment($client, 'failed', '99.00');
        $this->refund($p1, 'refunded', '100.00');
        $this->refund($p1, 'in_progress', '50.00');

        $this->actingAsApi($this->staff)->getJson(self::FINANCE_URL.'/overview?from=2026-12-01&to=2026-12-15')->assertOk()
            ->assertJsonPath('data.period.from', '2026-12-01')
            ->assertJsonPath('data.payments.succeeded_count', 2)
            ->assertJsonPath('data.payments.captured.amount', '1250.50')
            ->assertJsonPath('data.payments.failed_count', 1)
            ->assertJsonPath('data.refunds.refunded.amount', '100.00')
            ->assertJsonPath('data.refunds.in_progress.amount', '50.00')
            ->assertJsonPath('data.net_collected.amount', '1150.50')
            ->assertJsonPath('data.earnings.commission.amount', '210.00')
            ->assertJsonPath('data.adjustments.credits.amount', '50.00')
            ->assertJsonPath('data.payouts.paid.amount', '200.00')
            ->assertJsonPath('data.payouts.open_count', 1)
            ->assertJsonPath('data.provider_balances.negative_count', 1);
    }

    public function test_overview_default_period_and_limits(): void
    {
        $this->actingAsApi($this->staff)->getJson(self::FINANCE_URL.'/overview')->assertOk()
            ->assertJsonPath('data.period.from', '2026-12-01')->assertJsonPath('data.period.to', '2026-12-15');
        $this->actingAsApi($this->staff)->getJson(self::FINANCE_URL.'/overview?from=2026-12-10&to=2026-12-01')->assertStatus(422);
        $this->actingAsApi($this->staff)->getJson(self::FINANCE_URL.'/overview?from=2024-01-01&to=2026-12-01')->assertStatus(422);
    }

    public function test_finance_view_is_required(): void
    {
        $other = $this->staffWith(['finance.process']);
        foreach (['/overview', '/provider-balances', '/ledger-entries', '/reconciliation/checks', '/reconciliation/daily'] as $path) {
            $this->actingAsApi($other)->getJson(self::FINANCE_URL.$path)->assertForbidden();
        }
    }
}
