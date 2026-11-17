<?php

namespace Tests\Feature\Payments;

use App\Enums\BookingPaymentStatus;
use App\Enums\FulfilmentStatus;
use App\Enums\PaymentStatus;
use App\Enums\RefundKind;
use App\Enums\RefundStatus;
use App\Events\Payments\PaymentFulfilled;
use App\Events\Payments\PaymentSucceeded;
use App\Events\Payments\RefundSettled;
use App\Integrations\Payments\FakePaymentGateway;
use App\Integrations\Payments\WebhookEvent;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Provider;
use App\Models\SavedPaymentMethod;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsPayments;
use Tests\TestCase;

/**
 * Slice 10 (slice-10-contract.md §3, §6, §10.1; D185, D188): pay first, then accept, behind
 * `payments.booking_payment_required`.
 */
class OfferPaymentTest extends TestCase
{
    use BuildsPayments, RefreshDatabase;

    private Provider $noura;

    private Provider $huda;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeNow();
        $this->setUpMarket();
        $this->noura = $this->matchingProvider('Noura Lens');
        $this->huda = $this->matchingProvider('Huda Studio');
    }

    // ── Flag ────────────────────────────────────────────────────────────────────────────────

    public function test_flag_off_keeps_d113_and_refuses_payments(): void
    {
        $offer = $this->offerFrom($this->noura);

        $this->asClient()->getJson("/api/v1/client/offers/{$offer->id}")
            ->assertOk()->assertJsonPath('data.payment.required', false)
            ->assertJsonPath('data.payment.amount.amount', '1000.00')->assertJsonPath('data.payment.latest', null);
        $this->pay($offer)->assertStatus(409)->assertJsonPath('code', 'PAYMENT_NOT_REQUIRED');
        $this->assertSame(0, Payment::query()->count());

        $this->asClient()->withHeader('Idempotency-Key', (string) Str::uuid())->postJson("/api/v1/client/offers/{$offer->id}/accept")
            ->assertCreated()->assertJsonPath('data.payment_status', 'not_required');
    }

    public function test_flag_on_refuses_a_plain_accept(): void
    {
        $this->requirePayment();
        $offer = $this->offerFrom($this->noura);

        $this->asClient()->getJson("/api/v1/client/offers/{$offer->id}")->assertJsonPath('data.payment.required', true);
        $this->asClient()->withHeader('Idempotency-Key', (string) Str::uuid())->postJson("/api/v1/client/offers/{$offer->id}/accept")
            ->assertStatus(409)->assertJsonPath('code', 'PAYMENT_REQUIRED');
        $this->assertSame(0, Booking::query()->count());
    }

    public function test_a_replayed_accept_from_before_the_flag_still_answers(): void
    {
        $offer = $this->offerFrom($this->noura);
        $key = (string) Str::uuid();
        $this->asClient()->withHeader('Idempotency-Key', $key)->postJson("/api/v1/client/offers/{$offer->id}/accept")->assertCreated();
        $this->requirePayment();

        $this->asClient()->withHeader('Idempotency-Key', $key)->postJson("/api/v1/client/offers/{$offer->id}/accept")->assertOk();
    }

    // ── Success ─────────────────────────────────────────────────────────────────────────────

    public function test_paying_confirms_the_booking(): void
    {
        Event::fake([PaymentSucceeded::class, PaymentFulfilled::class]);
        $this->requirePayment();
        $request = $this->eventRequest();
        $offer = $this->offerFrom($this->noura, $request, '1200.50');
        $other = $this->offerFrom($this->huda, $request, '900');

        $res = $this->pay($offer, ['method' => 'card', 'token' => $this->cardToken('4242', 'mada')])->assertCreated()
            ->assertJsonPath('data.status', 'succeeded')
            ->assertJsonPath('data.fulfilment', 'fulfilled')
            ->assertJsonPath('data.amount.amount', '1200.50')
            ->assertJsonPath('data.card.brand', 'mada')->assertJsonPath('data.card.last4', '4242')
            ->assertJsonPath('data.payable.type', 'offer')->assertJsonPath('data.payable.id', $offer->id)
            ->assertJsonPath('data.refund.status', 'none')
            ->assertJsonPath('data.next_action', null);
        $this->assertMatchesRegularExpression('/^PY-\d{7}$/', $res->json('data.reference'));

        $booking = Booking::query()->sole();
        $payment = Payment::query()->sole();
        $res->assertJsonPath('data.booking.id', $booking->id);
        $this->assertSame($payment->id, $booking->payment_id);
        $this->assertSame(BookingPaymentStatus::Paid, $booking->payment_status);
        $this->assertSame('payment:'.$payment->id, $booking->idempotency_key);
        $this->assertSame('selected', $offer->fresh()->status->value);
        $this->assertSame('not_selected', $other->fresh()->status->value);
        $this->assertSame('confirmed', $request->fresh()->status->value);
        $this->assertNotNull($payment->gateway_reference);
        $this->assertSame('fake', $payment->gateway);
        $this->assertEquals(['brand' => 'mada', 'last4' => '4242', 'exp_month' => 12, 'exp_year' => 2030], $payment->card); // MySQL JSON reorders keys
        $this->assertNotNull($payment->expires_at); // D191: 30-minute lifetime
        Event::assertDispatched(PaymentSucceeded::class);
        Event::assertDispatched(PaymentFulfilled::class, fn (PaymentFulfilled $e) => $e->fulfilledId === $booking->id && $e->payableType === 'offer');

        $this->asClient()->getJson("/api/v1/client/bookings/{$booking->id}")->assertOk()
            ->assertJsonPath('data.payment_status', 'paid')->assertJsonPath('data.payment.reference', $payment->reference);
        $this->asClient()->getJson("/api/v1/client/offers/{$offer->id}")->assertJsonPath('data.payment.latest.id', $payment->id);
    }

    public function test_apple_pay_and_saved_cards(): void
    {
        $this->requirePayment();
        $this->pay($this->offerFrom($this->noura), ['method' => 'apple_pay', 'token' => FakePaymentGateway::APPLE_PAY_TOKEN])
            ->assertCreated()->assertJsonPath('data.method', 'apple_pay')->assertJsonPath('data.card', null)
            ->assertJsonPath('data.fulfilment', 'fulfilled');

        $saved = $this->asClient()->postJson('/api/v1/client/payment-methods', ['token' => $this->cardToken('1111', 'mastercard')])->json('data.id');
        $this->pay($this->offerFrom($this->huda), ['method' => 'card', 'payment_method_id' => $saved])->assertCreated()
            ->assertJsonPath('data.card.last4', '1111')->assertJsonPath('data.fulfilment', 'fulfilled');
        $this->assertSame($saved, Payment::query()->latest('id')->first()->saved_payment_method_id);
    }

    public function test_save_method_saves_the_card_only_on_success(): void
    {
        $this->requirePayment();
        $this->pay($this->offerFrom($this->noura), ['method' => 'card', 'token' => $this->cardToken('0002'), 'save_method' => true])
            ->assertCreated()->assertJsonPath('data.status', 'failed');
        $this->assertSame(0, SavedPaymentMethod::query()->count());

        $this->pay($this->offerFrom($this->huda), ['method' => 'card', 'token' => $this->cardToken('4242'), 'save_method' => true])
            ->assertCreated()->assertJsonPath('data.status', 'succeeded');
        $this->assertSame('4242', SavedPaymentMethod::query()->sole()->last4);
    }

    // ── Declines ────────────────────────────────────────────────────────────────────────────

    public function test_a_declined_card_is_a_failed_payment_not_an_error(): void
    {
        $this->requirePayment();
        $offer = $this->offerFrom($this->noura);
        $key = (string) Str::uuid();

        $this->pay($offer, ['method' => 'card', 'token' => $this->cardToken('9995')], $key)->assertCreated()
            ->assertJsonPath('data.status', 'failed')->assertJsonPath('data.failure_code', 'insufficient_funds')
            ->assertJsonPath('data.failure_message', 'The card does not have enough balance.')
            ->assertJsonPath('data.fulfilment', 'not_applicable')->assertJsonPath('data.booking', null);
        $this->assertSame(0, Booking::query()->count());

        // Same key → the same failed payment; a new key → a new attempt.
        $this->pay($offer, ['method' => 'card', 'token' => $this->cardToken('9995')], $key)->assertOk()->assertJsonPath('data.status', 'failed');
        $this->pay($offer, ['method' => 'card', 'token' => $this->cardToken('4242')])->assertCreated()->assertJsonPath('data.status', 'succeeded');
        $this->assertSame(2, Payment::query()->count());
    }

    public function test_an_invalid_or_expired_token_is_refused_before_charging(): void
    {
        $this->requirePayment();
        $offer = $this->offerFrom($this->noura);

        $this->pay($offer, ['method' => 'card', 'token' => 'tok_garbage'])->assertStatus(422)->assertJsonPath('code', 'PAYMENT_METHOD_INVALID');
        $this->pay($offer, ['method' => 'card', 'token' => $this->cardToken('4242', 'visa', 1, 2026)])->assertStatus(422)->assertJsonPath('code', 'PAYMENT_METHOD_INVALID');
        $this->assertSame(0, Payment::query()->count());
    }

    // ── 3-D Secure ──────────────────────────────────────────────────────────────────────────

    public function test_requires_action_then_webhook_confirms_the_booking(): void
    {
        $this->requirePayment();
        $offer = $this->offerFrom($this->noura);

        $res = $this->pay($offer, ['method' => 'card', 'token' => $this->cardToken('3155')])->assertCreated()
            ->assertJsonPath('data.status', 'requires_action')->assertJsonPath('data.next_action.type', 'redirect');
        $this->assertStringContainsString('/webhooks/payments/fake/checkout/', $res->json('data.next_action.url'));
        $payment = Payment::query()->sole();
        $this->assertSame(0, Booking::query()->count());

        // The app's redirect decides nothing: polling asks the gateway, which is still waiting.
        $this->travel(10)->seconds();
        $this->asClient()->getJson("/api/v1/client/payments/{$payment->id}")->assertOk()->assertJsonPath('data.status', 'requires_action');

        app(FakePaymentGateway::class)->settle($payment->gateway_reference, true);
        $this->webhook(WebhookEvent::PAYMENT_SUCCEEDED, $payment->gateway_reference)->assertOk()->assertJsonPath('data.result', 'applied');

        $this->asClient()->getJson("/api/v1/client/payments/{$payment->id}")->assertOk()
            ->assertJsonPath('data.status', 'succeeded')->assertJsonPath('data.fulfilment', 'fulfilled')
            ->assertJsonPath('data.booking.id', Booking::query()->sole()->id);
    }

    public function test_the_fake_checkout_page_settles_and_delivers_the_webhook(): void
    {
        $this->requirePayment();
        $this->pay($this->offerFrom($this->noura), ['method' => 'card', 'token' => $this->cardToken('3155')])->assertCreated();
        $payment = Payment::query()->sole();

        $this->get('/webhooks/payments/fake/checkout/'.$payment->gateway_reference)->assertOk()->assertSee($payment->reference);
        $this->post('/webhooks/payments/fake/checkout/'.$payment->gateway_reference, ['decision' => 'decline'])->assertOk();

        $this->assertSame(PaymentStatus::Failed, $payment->fresh()->status);
        $this->assertSame('authentication_failed', $payment->fresh()->failure_code);
    }

    public function test_a_timeout_is_unknown_and_polling_settles_it(): void
    {
        $this->requirePayment();
        $this->pay($this->offerFrom($this->noura), ['method' => 'card', 'token' => $this->cardToken('0119')])->assertCreated()
            ->assertJsonPath('data.status', 'unknown');
        $payment = Payment::query()->sole();
        $this->assertNull($payment->gateway_reference);

        $this->travel(10)->seconds();
        $this->asClient()->getJson("/api/v1/client/payments/{$payment->id}")
            ->assertJsonPath('data.status', 'succeeded')->assertJsonPath('data.fulfilment', 'fulfilled');
        $this->assertNotNull($payment->fresh()->gateway_reference);
    }

    // ── Acceptance lost after payment (D185) ────────────────────────────────────────────────

    public function test_pre_check_refuses_before_charging(): void
    {
        $this->requirePayment();
        $offer = $this->offerFrom($this->noura);
        $this->storeBlock($this->noura, '2026-12-05T15:30:00Z', '2026-12-05T17:00:00Z');

        $this->pay($offer)->assertStatus(409)->assertJsonPath('code', 'SLOT_UNAVAILABLE');
        $this->assertSame(0, Payment::query()->count());
    }

    public function test_a_slot_lost_after_payment_is_refunded_in_full(): void
    {
        Event::fake([RefundSettled::class]);
        $this->requirePayment();
        $offer = $this->offerFrom($this->noura, null, '750');
        $this->pay($offer, ['method' => 'card', 'token' => $this->cardToken('3155')])->assertCreated();
        $payment = Payment::query()->sole();

        // While she was at the bank, the provider blocked the time.
        $this->storeBlock($this->noura, '2026-12-05T15:30:00Z', '2026-12-05T17:00:00Z');
        app(FakePaymentGateway::class)->settle($payment->gateway_reference, true);
        $this->webhook(WebhookEvent::PAYMENT_SUCCEEDED, $payment->gateway_reference)->assertOk();

        $payment->refresh();
        $this->assertSame(PaymentStatus::Succeeded, $payment->status);
        $this->assertSame(FulfilmentStatus::Failed, $payment->fulfilment_status);
        $this->assertSame('SLOT_UNAVAILABLE', $payment->fulfilment_error);
        $refund = $payment->refunds()->sole();
        $this->assertSame(RefundKind::AcceptanceFailed, $refund->kind);
        $this->assertSame(RefundStatus::Refunded, $refund->status);
        $this->assertSame('750.00', $refund->amount);
        $this->assertNull($refund->initiated_by);
        $this->assertSame(0, Booking::query()->count());
        Event::assertDispatched(RefundSettled::class, fn (RefundSettled $e) => $e->status === 'refunded');

        $this->asClient()->getJson("/api/v1/client/payments/{$payment->id}")
            ->assertJsonPath('data.fulfilment', 'failed')->assertJsonPath('data.fulfilment_error', 'SLOT_UNAVAILABLE')
            ->assertJsonPath('data.refund.status', 'full')->assertJsonPath('data.refund.refunded_amount.amount', '750.00');
    }

    public function test_paying_two_offers_of_one_request_books_one_and_refunds_the_other(): void
    {
        $this->requirePayment();
        $request = $this->eventRequest();
        $a = $this->offerFrom($this->noura, $request);
        $b = $this->offerFrom($this->huda, $request);
        $this->pay($a, ['method' => 'card', 'token' => $this->cardToken('3155')])->assertCreated();
        $this->pay($b, ['method' => 'card', 'token' => $this->cardToken('3155')])->assertCreated();
        [$pa, $pb] = Payment::query()->orderBy('id')->get()->all();

        foreach ([$pa, $pb] as $p) {
            app(FakePaymentGateway::class)->settle($p->gateway_reference, true);
            $this->webhook(WebhookEvent::PAYMENT_SUCCEEDED, $p->gateway_reference)->assertOk();
        }

        $this->assertSame($pa->id, Booking::query()->sole()->payment_id);
        $this->assertSame(FulfilmentStatus::Failed, $pb->fresh()->fulfilment_status);
        $this->assertSame('ALREADY_BOOKED', $pb->fresh()->fulfilment_error);
        $this->assertSame(RefundStatus::Refunded, $pb->fresh()->refunds()->sole()->status);
    }

    // ── Several attempts (§6.4) ─────────────────────────────────────────────────────────────

    public function test_a_new_attempt_cancels_the_open_one_and_a_late_success_is_refunded(): void
    {
        $this->requirePayment();
        $offer = $this->offerFrom($this->noura);
        $this->pay($offer, ['method' => 'card', 'token' => $this->cardToken('3155')])->assertCreated();
        $old = Payment::query()->sole();
        $this->pay($offer, ['method' => 'card', 'token' => $this->cardToken('3155')])->assertCreated();

        $this->assertSame(PaymentStatus::Cancelled, $old->fresh()->status);

        // The bank still approves the abandoned attempt.
        $this->webhook(WebhookEvent::PAYMENT_SUCCEEDED, $old->gateway_reference)->assertOk();
        $old->refresh();
        $this->assertSame(PaymentStatus::Succeeded, $old->status);
        $this->assertSame(FulfilmentStatus::NotApplicable, $old->fulfilment_status);
        $this->assertSame(RefundKind::SupersededAttempt, $old->refunds()->sole()->kind);
        $this->assertSame(0, Booking::query()->count());
    }

    // ── Idempotency & validation ────────────────────────────────────────────────────────────

    public function test_same_key_replays_and_another_body_is_a_duplicate(): void
    {
        $this->requirePayment();
        $offer = $this->offerFrom($this->noura);
        $key = (string) Str::uuid();
        $id = $this->pay($offer, [], $key)->assertCreated()->json('data.id');

        $this->pay($offer, [], $key)->assertOk()->assertJsonPath('data.id', $id);
        $this->pay($offer, ['method' => 'apple_pay', 'token' => FakePaymentGateway::APPLE_PAY_TOKEN], $key)
            ->assertStatus(409)->assertJsonPath('code', 'DUPLICATE_REQUEST');
        $this->assertSame(1, Payment::query()->count());
    }

    public function test_validation(): void
    {
        $this->requirePayment();
        $offer = $this->offerFrom($this->noura);

        $this->asGuest()->asClient()->postJson("/api/v1/client/offers/{$offer->id}/payments", ['method' => 'card', 'token' => 'x'])
            ->assertStatus(422)->assertJsonValidationErrors('idempotency_key');
        $this->pay($offer, ['method' => 'bitcoin', 'token' => 'x'])->assertStatus(422)->assertJsonValidationErrors('method');
        $this->pay($offer, ['method' => 'card'])->assertStatus(422)->assertJsonValidationErrors(['payment_method_id', 'token']);
        $this->pay($offer, ['method' => 'card', 'token' => 'x', 'payment_method_id' => 1])->assertStatus(422)->assertJsonValidationErrors('payment_method_id');
        $this->pay($offer, ['method' => 'apple_pay'])->assertStatus(422)->assertJsonValidationErrors('token');
        $this->pay($offer, ['method' => 'card', 'payment_method_id' => 1, 'save_method' => true])->assertStatus(422)->assertJsonValidationErrors('save_method');
        $this->pay($offer, ['method' => 'card', 'token' => 'x', 'card_number' => '4111111111111111'])->assertStatus(422)->assertJsonValidationErrors('card_number');
        $this->assertSame(0, Payment::query()->count());
    }

    // ── Access ──────────────────────────────────────────────────────────────────────────────

    public function test_access_rules(): void
    {
        $this->requirePayment();
        $offer = $this->offerFrom($this->noura);
        $stranger = User::factory()->client()->create();

        $this->asGuest()->postJson("/api/v1/client/offers/{$offer->id}/payments")->assertStatus(401);
        $this->pay($offer, [], null, $this->noura->user)->assertStatus(403);
        $this->pay($offer, [], null, $stranger)->assertStatus(404);

        $mine = $this->paid($offer);
        $this->asClient($stranger)->getJson("/api/v1/client/payments/{$mine->id}")->assertStatus(404);

        $foreignMethod = SavedPaymentMethod::query()->create(['user_id' => $stranger->id, 'gateway' => 'fake', 'gateway_token' => $this->cardToken(),
            'token_hash' => hash('sha256', 'x'), 'active_token_hash' => hash('sha256', 'x'), 'brand' => 'visa', 'last4' => '4242', 'exp_month' => 12, 'exp_year' => 2030]);
        $this->pay($this->offerFrom($this->huda), ['method' => 'card', 'payment_method_id' => $foreignMethod->id])->assertStatus(404);
    }
}
