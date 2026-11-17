<?php

namespace Tests\Feature\Payouts;

use App\Enums\LedgerEntryType;
use App\Models\Provider;
use App\Models\ProviderLedgerEntry;
use App\Services\Payouts\EarningsRecorder;
use App\Services\Payouts\S10Payments;
use App\Services\Settings\AppSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsPayouts;
use Tests\TestCase;

/**
 * Slice 11 §4 (D215, D218–D220): one earning per paid and completed booking with gross / commission / net, held for
 * `payouts.hold_days`; refunds before it lower the gross, refunds after it write a negative adjustment.
 */
class EarningsTest extends TestCase
{
    use BuildsPayouts, RefreshDatabase;

    private Provider $noura;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeNow();
        $this->setUpMarket();
        $this->noura = $this->matchingProvider('Noura Lens');
    }

    public function test_earnings_answer_not_configured_while_a_setting_is_null(): void
    {
        $this->earnings($this->noura)->assertStatus(409)->assertJsonPath('code', 'NOT_CONFIGURED');
        $this->configurePayouts('15', null);
        $this->earnings($this->noura)->assertStatus(409)->assertJsonPath('code', 'NOT_CONFIGURED');
        $this->asProvider($this->noura)->getJson('/api/v1/provider/earnings/entries')->assertStatus(409);
        $this->configurePayouts('15', 7);
        $this->earnings($this->noura)->assertOk()->assertJsonPath('data.commission_percent', '15.00')->assertJsonPath('data.hold_days', 7);
    }

    public function test_completing_a_paid_booking_records_gross_commission_and_net(): void
    {
        $this->configurePayouts('15', 7);
        $booking = $this->paidCompletedBooking($this->noura, '1000.00');

        $entry = ProviderLedgerEntry::query()->sole();
        $this->assertSame(LedgerEntryType::Earning, $entry->type);
        $this->assertSame($booking->id, $entry->booking_id);
        $this->assertSame(100000, $entry->gross_cents);
        $this->assertSame(15000, $entry->commission_cents);
        $this->assertSame(85000, $entry->amount_cents);
        $this->assertSame(1500, $entry->commission_bps);
        $this->assertSame('2026-12-12T16:00:00Z', $entry->available_at->utc()->toIso8601ZuluString());

        $this->earnings($this->noura)->assertOk()
            ->assertJsonPath('data.pending.amount', '850.00')
            ->assertJsonPath('data.available.amount', '0.00')
            ->assertJsonPath('data.lifetime.gross.amount', '1000.00')
            ->assertJsonPath('data.lifetime.commission.amount', '150.00')
            ->assertJsonPath('data.lifetime.net.amount', '850.00')
            ->assertJsonPath('data.next_available_at', '2026-12-12T16:00:00Z');

        $this->asProvider($this->noura)->getJson('/api/v1/provider/earnings/entries')->assertOk()
            ->assertJsonPath('data.0.type', 'earning')
            ->assertJsonPath('data.0.status', 'pending')
            ->assertJsonPath('data.0.amount.amount', '850.00')
            ->assertJsonPath('data.0.gross.amount', '1000.00')
            ->assertJsonPath('data.0.commission.amount', '150.00')
            ->assertJsonPath('data.0.commission_percent', '15.00')
            ->assertJsonPath('data.0.booking.reference', $booking->reference);
    }

    public function test_the_net_becomes_available_after_the_hold(): void
    {
        $this->configurePayouts('15', 7);
        $this->paidCompletedBooking($this->noura);
        $this->at('2026-12-12T16:00:00Z');

        $this->earnings($this->noura)->assertOk()
            ->assertJsonPath('data.available.amount', '850.00')
            ->assertJsonPath('data.pending.amount', '0.00')
            ->assertJsonPath('data.next_available_at', null);
    }

    public function test_commission_rounds_half_up_to_the_cent(): void
    {
        $this->configurePayouts('12.5', 0);
        $this->paidCompletedBooking($this->noura, '100.05'); // 12.50625 → 12.51

        $entry = ProviderLedgerEntry::query()->sole();
        $this->assertSame(1251, $entry->commission_cents);
        $this->assertSame(8754, $entry->amount_cents);
    }

    public function test_an_unpaid_booking_earns_nothing(): void
    {
        $this->configurePayouts();
        $this->completedBooking($this->noura); // payment_status not_required (D113 flag off)

        $this->assertSame(0, ProviderLedgerEntry::query()->count());
    }

    public function test_bookings_completed_before_the_settings_are_back_filled_once(): void
    {
        $booking = $this->paidCompletedBooking($this->noura);
        $this->assertSame(0, ProviderLedgerEntry::query()->count());

        $this->configurePayouts('10', 3);
        $this->artisan('payouts:record-earnings')->assertSuccessful();
        $this->artisan('payouts:record-earnings')->assertSuccessful();

        $entry = ProviderLedgerEntry::query()->sole();
        $this->assertSame($booking->id, $entry->booking_id);
        $this->assertSame(10000, $entry->commission_cents);
        $this->assertSame('2026-12-08T16:00:00Z', $entry->available_at->utc()->toIso8601ZuluString()); // from completed_at
    }

    public function test_recording_is_idempotent(): void
    {
        $this->configurePayouts();
        $booking = $this->paidCompletedBooking($this->noura);
        $this->completeBooking($this->noura, $booking)->assertOk(); // completion is idempotent (D125)
        app(EarningsRecorder::class)->record($booking);

        $this->assertSame(1, ProviderLedgerEntry::query()->count());
    }

    public function test_the_entry_keeps_its_percent_when_the_setting_changes(): void
    {
        $this->configurePayouts('15', 7);
        $this->paidCompletedBooking($this->noura);
        app(AppSettings::class)->set('payouts.commission_percent', '30');

        $this->assertSame(15000, ProviderLedgerEntry::query()->sole()->commission_cents);
        $this->earnings($this->noura)->assertJsonPath('data.lifetime.commission.amount', '150.00')
            ->assertJsonPath('data.commission_percent', '30.00');
    }

    public function test_a_refund_settled_before_the_earning_lowers_the_gross(): void
    {
        $this->configurePayouts('10', 7);
        $booking = $this->paidBooking($this->noura, '1000.00');
        $refundId = $this->settledRefund($booking, '200.00');
        DB::table('bookings')->where('id', $booking->id)->update(['payment_status' => 'partially_refunded']);
        $this->at(self::EVENT_STARTS);
        $this->startBooking($this->noura, $booking)->assertOk();
        $this->completeBooking($this->noura, $booking)->assertOk();

        $entry = ProviderLedgerEntry::query()->sole();
        $this->assertSame(80000, $entry->gross_cents);
        $this->assertSame(72000, $entry->amount_cents);
        $this->assertSame([$refundId], $entry->meta['netted_refund_ids']);

        $this->settle($refundId); // the listener runs later: already netted, nothing more
        $this->assertSame(1, ProviderLedgerEntry::query()->count());
    }

    public function test_a_refund_settled_after_the_earning_writes_one_adjustment(): void
    {
        $this->configurePayouts('15', 7);
        $booking = $this->paidCompletedBooking($this->noura, '1000.00');
        $refundId = $this->settledRefund($booking, '100.00');

        $this->settle($refundId);
        $this->settle($refundId);

        $adjustment = ProviderLedgerEntry::query()->where('type', LedgerEntryType::RefundAdjustment)->sole();
        $this->assertSame(-8500, $adjustment->amount_cents); // 100 − 15 % commission
        $this->assertSame(-10000, $adjustment->gross_cents);
        $this->assertSame(-1500, $adjustment->commission_cents);
        $this->assertSame('refund:'.$refundId, $adjustment->entry_key);
        $this->earnings($this->noura)->assertJsonPath('data.pending.amount', '765.00')
            ->assertJsonPath('data.lifetime.gross.amount', '900.00');
    }

    public function test_a_lost_adjustment_is_back_filled(): void
    {
        $this->configurePayouts('15', 7);
        $booking = $this->paidCompletedBooking($this->noura);
        $this->settledRefund($booking, '100.00');

        $this->artisan('payouts:record-earnings')->assertSuccessful();

        $this->assertSame(1, ProviderLedgerEntry::query()->where('type', LedgerEntryType::RefundAdjustment)->count());
    }

    public function test_only_providers_reach_earnings(): void
    {
        $this->configurePayouts();
        // Guest first: the token and the resolved guard user persist between requests in one test.
        $this->getJson('/api/v1/provider/earnings')->assertStatus(401);
        $this->asClient()->getJson('/api/v1/provider/earnings')->assertStatus(403);
    }

    public function test_she_sees_only_her_own_entries(): void
    {
        $this->configurePayouts();
        $huda = $this->matchingProvider('Huda Studio');
        $this->paidCompletedBooking($huda);

        $this->asProvider($this->noura)->getJson('/api/v1/provider/earnings/entries')->assertOk()->assertJsonCount(0, 'data');
        $this->earnings($this->noura)->assertJsonPath('data.pending.amount', '0.00');
    }

    public function test_entries_list_a_slice_19_staff_adjustment(): void
    {
        $this->configurePayouts('15', 0);
        $this->paidCompletedBooking($this->noura);
        // Exactly the row Finance\ProviderLedgerWriter writes (type `adjustment`, no gross / commission, no hold).
        DB::table('provider_ledger_entries')->insert([
            'provider_id' => $this->noura->id, 'type' => 'adjustment', 'amount_cents' => -2500, 'booking_id' => null,
            'payout_request_id' => null, 'gross_cents' => null, 'commission_cents' => null, 'commission_bps' => null,
            'available_at' => now(), 'entry_key' => 'adjustment:1', 'meta' => json_encode(['adjustment_id' => 1]),
            'created_at' => now()->addSecond(), 'updated_at' => now()->addSecond(),
        ]);

        $this->asProvider($this->noura)->getJson('/api/v1/provider/earnings/entries')->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.type', 'adjustment')
            ->assertJsonPath('data.0.type_label', 'Balance adjustment')
            ->assertJsonPath('data.0.amount.amount', '-25.00')
            ->assertJsonPath('data.0.gross', null)
            ->assertJsonPath('data.0.booking', null);
        $this->asProvider($this->noura)->getJson('/api/v1/provider/earnings/entries?filter[type]=adjustment')->assertOk()
            ->assertJsonCount(1, 'data');
        $this->earnings($this->noura)->assertOk()
            ->assertJsonPath('data.available.amount', '825.00') // 850 − 25
            ->assertJsonPath('data.lifetime.net.amount', '850.00'); // staff adjustments are not booking earnings
    }

    public function test_the_back_fill_skips_netted_and_unrelated_refunds(): void
    {
        $this->configurePayouts('10', 7);
        // A refund netted into the earning (settled before completion).
        $netted = $this->paidBooking($this->noura, '1000.00');
        $this->settledRefund($netted, '200.00');
        $this->at(self::EVENT_STARTS);
        $this->startBooking($this->noura, $netted)->assertOk();
        $this->completeBooking($this->noura, $netted)->assertOk();
        // A refund on a paid booking that is not completed (no earning).
        $this->at('2026-12-01T09:00:00Z');
        $open = $this->paidBooking($this->matchingProvider('Huda Studio'), '500.00');
        $this->settledRefund($open, '50.00');

        $this->assertSame([], app(S10Payments::class)->settledBookingRefundIds()->all());
        $this->artisan('payouts:record-earnings')->assertSuccessful();
        $this->assertSame(0, ProviderLedgerEntry::query()->where('type', LedgerEntryType::RefundAdjustment)->count());

        // A refund settled after the earning is selected once, then drops out.
        $late = $this->settledRefund($netted, '100.00');
        $this->assertSame([$late], app(S10Payments::class)->settledBookingRefundIds()->all());
        $this->artisan('payouts:record-earnings')->assertSuccessful();
        $this->assertSame([], app(S10Payments::class)->settledBookingRefundIds()->all());
    }
}
