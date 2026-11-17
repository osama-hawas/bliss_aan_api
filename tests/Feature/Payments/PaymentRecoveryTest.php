<?php

namespace Tests\Feature\Payments;

use App\Enums\FulfilmentStatus;
use App\Enums\PayableType;
use App\Enums\PaymentMethodType;
use App\Enums\PaymentStatus;
use App\Enums\RefundKind;
use App\Enums\RefundStatus;
use App\Integrations\Payments\FakePaymentGateway;
use App\Integrations\Payments\GatewayResult;
use App\Integrations\Payments\GatewayUnavailable;
use App\Integrations\Payments\PaymentGateway;
use App\Integrations\Payments\WebhookEvent;
use App\Models\Booking;
use App\Models\Offer;
use App\Models\Payment;
use App\Models\User;
use App\Services\Availability\DeadlockRetriesExhausted;
use App\Services\Payments\PaymentFulfilment;
use App\Services\Payments\PaymentInstrument;
use App\Services\Payments\PaymentIntents;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsPayments;
use Tests\TestCase;

/**
 * Slice 10 review fixes (H1, M1, M2, M3, L1, L2): money the gateway took is never left unrecorded or unrefunded.
 */
class PaymentRecoveryTest extends TestCase
{
    use BuildsPayments, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeNow();
        $this->setUpMarket();
        $this->requirePayment();
    }

    private function create(Offer $offer, string $last4 = '4242', ?string $key = null): array
    {
        return app(PaymentIntents::class)->create($this->client, PayableType::Offer, $offer,
            new PaymentInstrument(PaymentMethodType::Card, null, $this->cardToken($last4)), $key ?? (string) Str::uuid());
    }

    /**
     * A fake gateway that cannot be reached for status lookups.
     */
    private function unreachableLookups(): void
    {
        $this->app->instance(PaymentGateway::class, new class extends FakePaymentGateway
        {
            public function status(string $gatewayReference): GatewayResult
            {
                throw new GatewayUnavailable('down');
            }

            public function statusByKey(string $idempotencyKey): ?GatewayResult
            {
                throw new GatewayUnavailable('down');
            }
        });
    }

    // ── H1 ──────────────────────────────────────────────────────────────────────────────────

    public function test_a_retry_after_a_timeout_asks_the_gateway_and_charges_nothing_twice(): void
    {
        $offer = $this->offerFrom($this->matchingProvider());
        $this->pay($offer, ['method' => 'card', 'token' => $this->cardToken('0119')])->assertCreated()->assertJsonPath('data.status', 'unknown');
        $first = Payment::query()->sole();

        // She tries again: the timed-out charge is found by key (it succeeded) and confirms the booking; nothing new is charged.
        $this->pay($offer, ['method' => 'card', 'token' => $this->cardToken('4242')])->assertStatus(409)->assertJsonPath('code', 'ALREADY_BOOKED');

        $first->refresh();
        $this->assertSame(PaymentStatus::Succeeded, $first->status);
        $this->assertSame(FulfilmentStatus::Fulfilled, $first->fulfilment_status);
        $this->assertNotNull($first->gateway_reference);
        $this->assertSame(1, Payment::query()->count());
        $this->assertSame($first->id, Booking::query()->sole()->payment_id);
    }

    public function test_an_unknown_attempt_is_never_cancelled_while_the_gateway_cannot_be_asked(): void
    {
        $offer = $this->offerFrom($this->matchingProvider());
        [$first] = $this->create($offer, '0119');
        $this->assertSame(PaymentStatus::Unknown, $first->status);
        $this->unreachableLookups();

        // A new attempt does not cancel it…
        $this->create($offer, '3155');
        $this->assertSame(PaymentStatus::Unknown, $first->fresh()->status);

        // …and neither does expiry.
        $this->travel(31)->minutes();
        app(PaymentIntents::class)->expire($first);
        $this->assertSame(PaymentStatus::Unknown, $first->fresh()->status);
    }

    public function test_a_webhook_without_our_gateway_reference_matches_on_our_payment_reference(): void
    {
        $this->unreachableLookups();
        [$payment] = $this->create($this->offerFrom($this->matchingProvider()), '0119');
        $this->assertNull($payment->gateway_reference);
        $this->app->forgetInstance(PaymentGateway::class);

        $this->webhook2(WebhookEvent::PAYMENT_SUCCEEDED, 'fake_ch_late', $payment->reference)->assertOk()->assertJsonPath('data.result', 'applied');

        $payment->refresh();
        $this->assertSame('fake_ch_late', $payment->gateway_reference);
        $this->assertSame(PaymentStatus::Succeeded, $payment->status);
        $this->assertSame($payment->id, Booking::query()->sole()->payment_id);

        // Our reference never matches a payment already tied to another charge.
        $this->webhook2(WebhookEvent::PAYMENT_FAILED, 'fake_ch_other', $payment->reference)->assertOk()->assertJsonPath('data.result', 'unmatched');
    }

    public function test_reconcile_asks_about_cancelled_attempts_without_a_reference(): void
    {
        $offer = $this->offerFrom($this->matchingProvider());
        [$payment] = $this->create($offer, '0119'); // the gateway took it, we never got the reference
        // An older version (or a race) cancelled it blind.
        $payment->update(['status' => PaymentStatus::Cancelled, 'cancelled_at' => now(), 'fulfilment_status' => FulfilmentStatus::NotApplicable]);

        $this->travel(2)->minutes();
        $this->artisan('payments:reconcile')->assertSuccessful();

        $payment->refresh();
        $this->assertSame(PaymentStatus::Succeeded, $payment->status);
        $this->assertNotNull($payment->gateway_reference);
        $this->assertSame(RefundKind::SupersededAttempt, $payment->refunds()->sole()->kind);
        $this->assertSame(RefundStatus::Refunded, $payment->refunds()->sole()->status);
    }

    // ── M1 ──────────────────────────────────────────────────────────────────────────────────

    public function test_a_server_error_during_fulfilment_stays_pending_without_a_refund(): void
    {
        $this->failingAcceptor(new DeadlockRetriesExhausted);
        [$payment] = $this->create($this->offerFrom($this->matchingProvider()));

        $this->assertSame(PaymentStatus::Succeeded, $payment->status);
        $this->assertSame(FulfilmentStatus::Pending, $payment->fulfilment_status);
        $this->assertSame(0, $payment->refunds()->count());
    }

    // ── M3 + M2 ─────────────────────────────────────────────────────────────────────────────

    public function test_a_failed_automatic_refund_is_retried_then_left_to_staff(): void
    {
        $offer = $this->offerFrom($this->matchingProvider());
        [$payment] = $this->create($offer, '3155');
        // The request closes before the bank step, and every refund call fails at the gateway.
        $offer->serviceRequest->update(['status' => 'cancelled']);
        $this->forceRefundFailures();

        $this->webhook(WebhookEvent::PAYMENT_SUCCEEDED, $payment->gateway_reference)->assertOk();
        $this->assertSame(FulfilmentStatus::Failed, $payment->fresh()->fulfilment_status);
        $this->assertSame([RefundStatus::Failed], $payment->refunds()->pluck('status')->all());

        foreach ([2, 3, 3] as $expected) {
            $this->travel(2)->minutes();
            $this->artisan('payments:reconcile')->assertSuccessful();
            $this->assertSame($expected, $payment->refunds()->count());
        }
        $this->assertSame(['auto:acceptance_failed', 'auto:acceptance_failed:2', 'auto:acceptance_failed:3'],
            $payment->refunds()->pluck('idempotency_key')->all());
        $this->assertSame(PaymentFulfilment::MAX_AUTOMATIC_REFUNDS, $payment->refunds()->count());
    }

    public function test_a_retried_automatic_refund_that_succeeds_stops_the_retries(): void
    {
        $offer = $this->offerFrom($this->matchingProvider());
        [$payment] = $this->create($offer, '3155');
        $offer->serviceRequest->update(['status' => 'cancelled']);
        $this->forceRefundFailures();
        $this->webhook(WebhookEvent::PAYMENT_SUCCEEDED, $payment->gateway_reference)->assertOk();

        $this->app->forgetInstance(PaymentGateway::class); // refunds work again
        $this->travel(2)->minutes();
        $this->artisan('payments:reconcile')->assertSuccessful();
        $this->travel(2)->minutes();
        $this->artisan('payments:reconcile')->assertSuccessful();

        $this->assertSame([RefundStatus::Failed, RefundStatus::Refunded], $payment->refunds()->pluck('status')->all());
        $this->assertSame(0, $payment->fresh()->refundableCents());
    }

    // ── L1 ──────────────────────────────────────────────────────────────────────────────────

    public function test_a_late_success_after_failed_is_recorded_and_refunded(): void
    {
        [$payment] = $this->create($this->offerFrom($this->matchingProvider()), '3155');
        $this->webhook(WebhookEvent::PAYMENT_FAILED, $payment->gateway_reference, 'authentication_failed')->assertOk();
        $this->webhook(WebhookEvent::PAYMENT_SUCCEEDED, $payment->gateway_reference)->assertOk();

        $payment->refresh();
        $this->assertSame(PaymentStatus::Succeeded, $payment->status);
        $this->assertSame(FulfilmentStatus::NotApplicable, $payment->fulfilment_status);
        $this->assertSame(RefundStatus::Refunded, $payment->refunds()->sole()->status);
        $this->assertSame(0, Booking::query()->count());
    }

    // ── L2 ──────────────────────────────────────────────────────────────────────────────────

    public function test_staff_cannot_refund_while_the_booking_is_being_confirmed(): void
    {
        $this->failingAcceptor(new DeadlockRetriesExhausted);
        [$payment] = $this->create($this->offerFrom($this->matchingProvider()));
        $admin = User::factory()->admin()->create();
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin->assignRole('super_admin');

        $this->actingAsApi($admin)->withHeader('Accept-Language', 'en')->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/admin/payments/{$payment->id}/refunds", ['amount' => '10', 'reason' => 'Too early'])
            ->assertStatus(409)->assertJsonPath('code', 'INVALID_STATE');
        $this->assertSame(0, $payment->refunds()->count());
    }

    private function forceRefundFailures(): void
    {
        $this->app->instance(PaymentGateway::class, new class extends FakePaymentGateway
        {
            public function refund(string $gatewayReference, string $amount, string $idempotencyKey): GatewayResult
            {
                return new GatewayResult(GatewayResult::FAILED, 'fake_re_'.Str::lower(Str::random(12)), [], null, 'gateway_error');
            }
        });
    }

    private function webhook2(string $type, string $gatewayReference, string $paymentReference)
    {
        [$body, $headers] = FakePaymentGateway::webhook($type, $gatewayReference, null, null, null, $paymentReference);

        return $this->call('POST', '/webhooks/payments/fake', [], [], [], $this->transformHeadersToServerVars($headers + ['Accept' => 'application/json']), $body);
    }
}
