<?php

namespace Tests\Feature\Payments;

use App\Enums\FulfilmentStatus;
use App\Enums\PaymentStatus;
use App\Enums\RefundKind;
use App\Enums\RefundStatus;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Refund;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsPayments;
use Tests\TestCase;

/**
 * Slice 10 (slice-10-contract.md §6.3): `payments:reconcile`.
 */
class ReconcileCommandTest extends TestCase
{
    use BuildsPayments, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeNow();
        $this->setUpMarket();
        $this->requirePayment();
    }

    public function test_an_abandoned_attempt_expires_after_the_ttl_and_a_late_success_is_refunded(): void
    {
        $this->pay($this->offerFrom($this->matchingProvider()), ['method' => 'card', 'token' => $this->cardToken('3155')])->assertCreated();
        $payment = Payment::query()->sole();

        $this->travel(29)->minutes();
        $this->artisan('payments:reconcile')->assertSuccessful();
        $this->assertSame(PaymentStatus::RequiresAction, $payment->fresh()->status);

        $this->travel(2)->minutes();
        $this->artisan('payments:reconcile')->assertSuccessful();
        $this->assertSame(PaymentStatus::Cancelled, $payment->fresh()->status);

        // The fake page cannot settle a cancelled charge any more; a late gateway success still gets refunded.
        $this->webhook('payment.succeeded', $payment->gateway_reference)->assertOk();
        $this->assertSame(RefundKind::SupersededAttempt, $payment->fresh()->refunds()->sole()->kind);
        $this->assertSame(0, Booking::query()->count());
    }

    public function test_no_ttl_means_no_expiry(): void
    {
        $this->intentTtl(null);
        $this->pay($this->offerFrom($this->matchingProvider()), ['method' => 'card', 'token' => $this->cardToken('3155')])->assertCreated();

        $this->travel(5)->hours();
        $this->artisan('payments:reconcile')->assertSuccessful();
        $this->assertSame(PaymentStatus::RequiresAction, Payment::query()->sole()->status);
    }

    public function test_unknown_payments_are_settled(): void
    {
        $this->pay($this->offerFrom($this->matchingProvider()), ['method' => 'card', 'token' => $this->cardToken('0119')])->assertCreated();

        $this->travel(2)->minutes();
        $this->artisan('payments:reconcile')->assertSuccessful();

        $payment = Payment::query()->sole();
        $this->assertSame(PaymentStatus::Succeeded, $payment->status);
        $this->assertSame(FulfilmentStatus::Fulfilled, $payment->fulfilment_status);
        $this->assertSame($payment->id, Booking::query()->sole()->payment_id);
    }

    public function test_a_pending_fulfilment_is_retried(): void
    {
        $payment = $this->paid($this->offerFrom($this->matchingProvider()));
        // Simulate a crash between the success and the booking.
        Booking::query()->delete();
        $payment->update(['fulfilment_status' => FulfilmentStatus::Pending]);
        $payment->offer->update(['status' => 'active']);
        $payment->offer->serviceRequest->update(['status' => 'offers_received']);

        $this->travel(2)->minutes();
        $this->artisan('payments:reconcile')->assertSuccessful();

        $this->assertSame(FulfilmentStatus::Fulfilled, $payment->fresh()->fulfilment_status);
        $this->assertSame($payment->id, Booking::query()->sole()->payment_id);
    }

    public function test_a_refund_whose_gateway_call_failed_is_sent_again(): void
    {
        $payment = $this->paid($this->offerFrom($this->matchingProvider()));
        $refund = Refund::query()->create(['payment_id' => $payment->id, 'reference' => 'RF-0000001', 'kind' => RefundKind::Staff,
            'amount' => '20.00', 'reason' => 'Retry me', 'status' => RefundStatus::InProgress, 'idempotency_key' => 'k']);
        $payment->update(['refunded_amount' => '20.00']);

        $this->travel(2)->minutes();
        $this->artisan('payments:reconcile')->assertSuccessful();

        $this->assertSame(RefundStatus::Refunded, $refund->fresh()->status);
        $this->assertNotNull($refund->fresh()->gateway_reference);
    }
}
