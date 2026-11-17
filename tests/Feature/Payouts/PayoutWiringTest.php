<?php

namespace Tests\Feature\Payouts;

use App\Enums\LedgerEntryType;
use App\Enums\RefundKind;
use App\Enums\RefundStatus;
use App\Events\Payments\RefundSettled;
use App\Integrations\Payments\GatewayResult;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Provider;
use App\Models\ProviderLedgerEntry;
use App\Models\Refund;
use App\Services\Operations\BookingInterventions;
use App\Services\Payments\PaymentRefunds;
use App\Services\Payments\RefundStateMachine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsPayments;
use Tests\Concerns\BuildsPayouts;
use Tests\TestCase;

/**
 * Slice 11 through the real wiring (review M3): a booking paid with the Slice 10 flow, completed by the provider or by
 * staff (Slice 17), and refunds settled by S10's RefundStateMachine, whose after-commit RefundSettled reaches
 * Listeners\Payouts\RecordRefundAdjustment.
 */
class PayoutWiringTest extends TestCase
{
    use BuildsPayments, BuildsPayouts, RefreshDatabase {
        BuildsPayouts::asClient insteadof BuildsPayments;
    }

    private Provider $noura;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeNow();
        $this->setUpMarket();
        $this->noura = $this->matchingProvider('Noura Lens');
        $this->configurePayouts('15', 7);
        $this->requirePayment();
    }

    /**
     * Paid through `POST client/offers/{id}/payments` (fake gateway): the booking gets payment_id + `paid`.
     */
    private function realPaidBooking(): array
    {
        $payment = $this->paid($this->offerFrom($this->noura, null, '1000'));
        $booking = Booking::query()->where('payment_id', $payment->id)->sole();
        $this->assertSame('paid', $booking->payment_status->value);

        return [$booking, $payment];
    }

    private function startAndComplete(Booking $booking): void
    {
        $this->at(self::EVENT_STARTS);
        $this->startBooking($this->noura, $booking)->assertOk();
        $this->completeBooking($this->noura, $booking)->assertOk();
    }

    public function test_a_real_payment_earns_and_a_staff_refund_adjusts_through_refund_settled(): void
    {
        [$booking, $payment] = $this->realPaidBooking();
        $this->startAndComplete($booking);
        $this->assertSame(85000, ProviderLedgerEntry::query()->where('type', LedgerEntryType::Earning)->sole()->amount_cents);

        [$refund] = app(PaymentRefunds::class)->refund($payment->refresh(), '100.00', RefundKind::Staff, $this->payoutsOfficer(),
            'Partial refund', (string) Str::uuid());

        $this->assertSame(RefundStatus::Refunded, $refund->status);
        $adjustment = ProviderLedgerEntry::query()->where('type', LedgerEntryType::RefundAdjustment)->sole();
        $this->assertSame('refund:'.$refund->id, $adjustment->entry_key);
        $this->assertSame(-8500, $adjustment->amount_cents);
    }

    public function test_a_refund_in_progress_at_completion_is_adjusted_when_it_settles(): void
    {
        [$booking, $payment] = $this->realPaidBooking();
        $refund = Refund::query()->create([
            'payment_id' => $payment->id, 'reference' => 'RF-'.random_int(1_000_000, 9_999_999), 'kind' => RefundKind::Staff,
            'amount' => '200.00', 'reason' => 'Partial refund', 'status' => RefundStatus::InProgress,
            'idempotency_key' => (string) Str::uuid(),
        ]);
        Payment::query()->whereKey($payment->id)->update(['refunded_amount' => '200.00']); // S10 reserves it

        $this->startAndComplete($booking);
        $earning = ProviderLedgerEntry::query()->where('type', LedgerEntryType::Earning)->sole();
        $this->assertSame(100000, $earning->gross_cents); // in_progress is not netted
        $this->assertSame([], $earning->meta['netted_refund_ids']);

        app(RefundStateMachine::class)->apply($refund, new GatewayResult(GatewayResult::SUCCEEDED, 'fake_rf_'.Str::random(8)), 'webhook');

        $this->assertSame(-17000, ProviderLedgerEntry::query()->where('type', LedgerEntryType::RefundAdjustment)->sole()->amount_cents);
    }

    public function test_a_failed_refund_writes_nothing(): void
    {
        [$booking, $payment] = $this->realPaidBooking();
        $this->startAndComplete($booking);
        $refund = Refund::query()->create([
            'payment_id' => $payment->id, 'reference' => 'RF-'.random_int(1_000_000, 9_999_999), 'kind' => RefundKind::Staff,
            'amount' => '200.00', 'reason' => 'Partial refund', 'status' => RefundStatus::InProgress,
            'idempotency_key' => (string) Str::uuid(),
        ]);

        app(RefundStateMachine::class)->apply($refund, new GatewayResult(GatewayResult::FAILED, 'fake_rf_'.Str::random(8), [], null, 'gateway_error'), 'webhook');
        RefundSettled::dispatch($refund->id, $payment->id, 'failed');

        $this->assertSame(0, ProviderLedgerEntry::query()->where('type', LedgerEntryType::RefundAdjustment)->count());
    }

    public function test_staff_completion_records_the_earning(): void
    {
        [$booking] = $this->realPaidBooking();
        $this->at(self::EVENT_STARTS);
        $this->startBooking($this->noura, $booking)->assertOk();

        app(BookingInterventions::class)->complete($this->payoutsOfficer(), $booking, 'Provider did not confirm.', null);

        $earning = ProviderLedgerEntry::query()->where('type', LedgerEntryType::Earning)->sole();
        $this->assertSame($booking->id, $earning->booking_id);
        $this->assertSame(85000, $earning->amount_cents);
    }
}
