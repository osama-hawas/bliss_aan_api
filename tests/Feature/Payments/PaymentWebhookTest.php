<?php

namespace Tests\Feature\Payments;

use App\Enums\PaymentStatus;
use App\Integrations\Payments\FakePaymentGateway;
use App\Integrations\Payments\WebhookEvent;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\PaymentWebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsPayments;
use Tests\TestCase;

/**
 * Slice 10 (slice-10-contract.md §8, D189): signed, idempotent on (gateway, event_id), forward-only.
 */
class PaymentWebhookTest extends TestCase
{
    use BuildsPayments, RefreshDatabase;

    private Payment $payment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeNow();
        $this->setUpMarket();
        $this->requirePayment();
        $this->pay($this->offerFrom($this->matchingProvider()), ['method' => 'card', 'token' => $this->cardToken('3155')])->assertCreated();
        $this->payment = Payment::query()->sole();
    }

    public function test_bad_signatures_are_refused_and_nothing_is_stored(): void
    {
        [$body] = FakePaymentGateway::webhook(WebhookEvent::PAYMENT_SUCCEEDED, $this->payment->gateway_reference);
        $t = (string) now()->getTimestamp();

        $send = fn (array $headers) => $this->call('POST', '/webhooks/payments/fake', [], [], [],
            $this->transformHeadersToServerVars($headers + ['Accept' => 'application/json']), $body);

        $send([])->assertStatus(401)->assertJsonPath('code', 'INVALID_SIGNATURE');
        $send([FakePaymentGateway::SIGNATURE_HEADER => "t={$t},v1=".str_repeat('a', 64)])->assertStatus(401);
        $old = (string) now()->subMinutes(10)->getTimestamp();
        $send([FakePaymentGateway::SIGNATURE_HEADER => "t={$old},v1=".FakePaymentGateway::sign($old, $body)])->assertStatus(401);
        // A body changed after signing.
        $this->call('POST', '/webhooks/payments/fake', [], [], [],
            $this->transformHeadersToServerVars([FakePaymentGateway::SIGNATURE_HEADER => "t={$t},v1=".FakePaymentGateway::sign($t, $body), 'Accept' => 'application/json']),
            str_replace('succeeded', 'failed', $body))->assertStatus(401);

        $this->assertSame(0, PaymentWebhookEvent::query()->count());
        $this->assertSame(PaymentStatus::RequiresAction, $this->payment->fresh()->status);
    }

    public function test_no_secret_means_every_webhook_is_refused(): void
    {
        config(['services.payments.webhook_secret' => null]);
        $this->webhook(WebhookEvent::PAYMENT_SUCCEEDED, $this->payment->gateway_reference)->assertStatus(401);
    }

    public function test_unknown_gateway_is_a_404(): void
    {
        $this->postJson('/webhooks/payments/stripe', [])->assertStatus(404);
    }

    public function test_a_redelivered_event_is_applied_once(): void
    {
        $this->webhook(WebhookEvent::PAYMENT_SUCCEEDED, $this->payment->gateway_reference, null, 'evt_1')->assertOk()->assertJsonPath('data.result', 'applied');
        $this->webhook(WebhookEvent::PAYMENT_SUCCEEDED, $this->payment->gateway_reference, null, 'evt_1')->assertOk()->assertJsonPath('data.result', 'duplicate');

        $this->assertSame(1, PaymentWebhookEvent::query()->count());
        $this->assertSame(1, Booking::query()->count());
        $this->assertSame(1, DB::table('activity_events')->where('subject_type', $this->payment->getMorphClass())->where('event', 'payment_succeeded')->count());
    }

    public function test_out_of_order_events_never_move_a_settled_payment_back(): void
    {
        $this->webhook(WebhookEvent::PAYMENT_SUCCEEDED, $this->payment->gateway_reference)->assertOk();
        $this->webhook(WebhookEvent::PAYMENT_FAILED, $this->payment->gateway_reference, 'declined')->assertOk()->assertJsonPath('data.result', 'applied');

        $this->assertSame(PaymentStatus::Succeeded, $this->payment->fresh()->status);
        $this->assertNull($this->payment->fresh()->failure_code);
    }

    public function test_failed_webhook(): void
    {
        $this->webhook(WebhookEvent::PAYMENT_FAILED, $this->payment->gateway_reference, 'authentication_failed')->assertOk();

        $this->assertSame(PaymentStatus::Failed, $this->payment->fresh()->status);
        $this->assertSame('authentication_failed', $this->payment->fresh()->failure_code);
        $this->assertSame(0, Booking::query()->count());
    }

    public function test_unmatched_and_unknown_types_are_stored_and_acknowledged(): void
    {
        $this->webhook(WebhookEvent::PAYMENT_SUCCEEDED, 'fake_ch_nobody')->assertOk()->assertJsonPath('data.result', 'unmatched');
        $this->webhook('customer.updated', $this->payment->gateway_reference)->assertOk()->assertJsonPath('data.result', 'ignored');

        $this->assertSame(['unmatched', 'ignored'], PaymentWebhookEvent::query()->orderBy('id')->pluck('result')->all());
    }

    public function test_the_fake_checkout_page_is_hidden_outside_local_and_testing(): void
    {
        $this->app['env'] = 'production';
        $this->get('/webhooks/payments/fake/checkout/'.$this->payment->gateway_reference)->assertStatus(404);
    }
}
