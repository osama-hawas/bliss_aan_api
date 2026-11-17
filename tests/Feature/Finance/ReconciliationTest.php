<?php

namespace Tests\Feature\Finance;

use App\Models\Provider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsBookings;
use Tests\Concerns\BuildsFinance;
use Tests\TestCase;

/**
 * Slice 19 §5 (D296): each reconciliation check finds its broken row and ignores a healthy one; the daily summary per
 * Riyadh day. Read-only: nothing is changed. Run after the S10/S11 merge.
 */
class ReconciliationTest extends TestCase
{
    use BuildsBookings, BuildsFinance, RefreshDatabase;

    private User $staff;

    private Provider $noura;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeNow();
        $this->setUpMarket();
        $this->noura = $this->matchingProvider('Noura Lens');
        $this->staff = $this->staffWith(['finance.view']);
    }

    private function rows(string $check): array
    {
        return $this->actingAsApi($this->staff)->getJson(self::FINANCE_URL."/reconciliation/checks/{$check}")->assertOk()->json('data');
    }

    public function test_payment_checks(): void
    {
        $client = $this->client;
        $healthy = $this->payment($client, 'succeeded', '100.00');
        $stale = $this->payment($client, 'pending', '100.00', ['expires_at' => now()->subMinute()]);
        $unknown = $this->payment($client, 'unknown', '100.00');
        $this->payment($client, 'pending', '100.00'); // not expired yet
        $unfulfilled = $this->payment($client, 'succeeded', '100.00', ['fulfilment_status' => 'pending']);
        $lost = $this->payment($client, 'succeeded', '100.00', ['fulfilment_status' => 'failed', 'fulfilment_error' => 'SLOT_UNAVAILABLE']);
        $refundedLost = $this->payment($client, 'succeeded', '100.00', ['fulfilment_status' => 'failed', 'refunded_amount' => '100.00']);
        $this->refund($refundedLost, 'refunded', '100.00'); // fully refunded: matches neither check
        $mismatch = $this->payment($client, 'succeeded', '100.00', ['refunded_amount' => '30.00']);
        $this->refund($mismatch, 'refunded', '20.00');
        $this->refund($healthy, 'failed', '10.00', ['failure_code' => 'gateway_error']);
        $inProgress = $this->refund($healthy, 'in_progress', '10.00');
        DB::table('payments')->where('id', $healthy)->update(['refunded_amount' => '10.00']);

        $this->assertEqualsCanonicalizing([$stale, $unknown], array_column($this->rows('stale_payments'), 'id'));
        $this->assertSame([$unfulfilled], array_column($this->rows('fulfilment_pending'), 'id'));
        $lostRow = $this->rows('fulfilment_failed_unrefunded');
        $this->assertSame([$lost], array_column($lostRow, 'id'));
        $this->assertSame('SLOT_UNAVAILABLE', $lostRow[0]['detail']);
        $this->assertSame([$mismatch], array_column($this->rows('payment_refund_mismatch'), 'id'));
        $this->assertSame([$inProgress], array_column($this->rows('refunds_in_progress'), 'id'));
        $this->assertCount(1, $this->rows('refunds_failed'));
    }

    public function test_webhook_check(): void
    {
        DB::table('payment_webhook_events')->insert([
            ['gateway' => 'fake', 'event_id' => 'evt_ok', 'type' => 'payment.succeeded', 'gateway_reference' => 'g1', 'payload' => '{}', 'received_at' => now(), 'processed_at' => now(), 'result' => 'applied'],
            ['gateway' => 'fake', 'event_id' => 'evt_unmatched', 'type' => 'payment.succeeded', 'gateway_reference' => 'g2', 'payload' => '{}', 'received_at' => now(), 'processed_at' => now(), 'result' => 'unmatched'],
            ['gateway' => 'fake', 'event_id' => 'evt_stuck', 'type' => 'refund.succeeded', 'gateway_reference' => 'g3', 'payload' => '{}', 'received_at' => now(), 'processed_at' => null, 'result' => null],
        ]);

        $this->assertEqualsCanonicalizing(['evt_unmatched', 'evt_stuck'], array_column($this->rows('webhooks_unprocessed'), 'reference'));
    }

    public function test_ledger_checks(): void
    {
        $missing = $this->completedBooking($this->noura);
        $payment = $this->payment($this->client, 'succeeded', '1000.00');
        DB::table('bookings')->where('id', $missing->id)->update(['payment_id' => $payment, 'payment_status' => 'paid']);

        $rows = $this->rows('missing_earnings');
        $this->assertSame([$missing->id], array_column($rows, 'id'));
        $this->assertSame('1000.00', $rows[0]['amount']['amount']);

        // Earning recorded, one refund netted, one not in the ledger.
        $netted = $this->refund($payment, 'refunded', '100.00');
        $lost = $this->refund($payment, 'refunded', '50.00');
        $this->ledgerEntry($this->noura, 'earning', 76500, ['booking_id' => $missing->id, 'entry_key' => 'earning:'.$missing->id, 'meta' => json_encode(['netted_refund_ids' => [$netted]])]);
        $this->assertSame([], $this->rows('missing_earnings'));
        $this->assertSame([$lost], array_column($this->rows('refund_not_in_ledger'), 'id'));

        $this->ledgerEntry($this->noura, 'refund_adjustment', -4250, ['booking_id' => $missing->id, 'entry_key' => 'refund:'.$lost]);
        $this->assertSame([], $this->rows('refund_not_in_ledger'));

        $huda = $this->matchingProvider('Huda Studio');
        $this->ledgerEntry($huda, 'refund_adjustment', -100);
        $this->assertSame([$huda->id], array_column($this->rows('negative_balances'), 'id'));
    }

    public function test_summary_counts_and_unknown_check(): void
    {
        $this->payment($this->client, 'unknown', '10.00');

        $summary = collect($this->actingAsApi($this->staff)->getJson(self::FINANCE_URL.'/reconciliation/checks')->assertOk()->json('data'))->keyBy('key');
        $this->assertCount(11, $summary);
        $this->assertSame(1, $summary['stale_payments']['count']);
        $this->assertSame('10.00', $summary['stale_payments']['amount']['amount']);
        $this->assertNull($summary['webhooks_unprocessed']['amount']);
        $this->assertSame(0, $summary['pending_adjustments']['count']);

        $this->actingAsApi($this->staff)->getJson(self::FINANCE_URL.'/reconciliation/checks/nope')->assertNotFound();
    }

    public function test_daily_summary_uses_riyadh_days(): void
    {
        $this->travelTo(Carbon::parse('2026-12-03T22:30:00Z')); // 2026-12-04 01:30 Riyadh
        $this->payment($this->client, 'succeeded', '200.00');
        $this->travelTo(Carbon::parse('2026-12-04T10:00:00Z'));

        $days = collect($this->actingAsApi($this->staff)->getJson(self::FINANCE_URL.'/reconciliation/daily?from=2026-12-03&to=2026-12-04')->assertOk()
            ->assertJsonCount(2, 'data')->json('data'))->keyBy('date');
        $this->assertSame(0, $days['2026-12-03']['payments_count']);
        $this->assertSame('200.00', $days['2026-12-04']['captured']['amount']);
        $this->actingAsApi($this->staff)->getJson(self::FINANCE_URL.'/reconciliation/daily?from=2026-01-01&to=2026-12-04')->assertStatus(422);
    }
}
