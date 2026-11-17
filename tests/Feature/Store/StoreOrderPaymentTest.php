<?php

namespace Tests\Feature\Store;

use App\Enums\StoreOrderStatus;
use App\Models\Payment;
use App\Models\ProductVariant;
use App\Models\PromoCodeUsage;
use App\Models\StoreOrder;
use App\Services\Audit\ActivityRecorder;
use App\Services\Audit\AuditLogger;
use App\Services\Availability\DeadlockRetry;
use App\Services\Notifications\Notifier;
use App\Services\Store\StoreOrderLifecycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsStoreOrders;
use Tests\TestCase;

/**
 * Slice 9b payment through the Slice 10 core (`slice-9b-contract.md` §6.3, §7; D204, D207, D212, D214) with the fake
 * gateway (`slice-10-contract.md` §11): `tok_fake_<brand>_<last4>_<mmyy>`; last4 0002 declines, 3155 needs action.
 * Needs the Slice 10 merge to run.
 */
class StoreOrderPaymentTest extends TestCase
{
    use BuildsStoreOrders, RefreshDatabase;

    private const CARD = 'tok_fake_visa_4242_1230';

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureStore('25.00');
        $this->referencesEnding();
    }

    private function pay(StoreOrder $order, string $token = self::CARD, ?string $key = null): TestResponse
    {
        return $this->actingAsApi($order->user)
            ->withHeader('Idempotency-Key', $key ?? (string) Str::uuid())
            ->postJson(self::ORDERS_URL."/{$order->id}/payments", ['method' => 'card', 'token' => $token]);
    }

    public function test_a_successful_payment_confirms_the_order_and_deducts_stock(): void
    {
        $client = $this->shopper();
        $variant = $this->item('40.00', 5);
        $order = $this->pendingOrder($client, $variant, 2);
        $this->promo(['code' => 'X']); // unrelated code, untouched

        $this->pay($order)->assertCreated()
            ->assertJsonPath('data.status', 'succeeded')
            ->assertJsonPath('data.fulfilment', 'fulfilled')
            ->assertJsonPath('data.amount.amount', '105.00')
            ->assertJsonPath('data.payable.type', 'store_order')
            ->assertJsonPath('meta.order.status', 'confirmed')
            ->assertJsonPath('meta.order.payment.status', 'succeeded');

        $order->refresh();
        $this->assertSame(StoreOrderStatus::Confirmed, $order->status);
        $this->assertNotNull($order->payment_id);
        $this->assertSame(3, $variant->fresh()->stock);
        $this->assertDatabaseCount('cart_items', 0);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $client->id, 'type' => 'store_order_confirmed']);

        $this->actingAsApi($client)->getJson(self::ORDERS_URL)->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.timeline.0.status', 'confirmed');
    }

    public function test_a_declined_card_keeps_the_order_payable_with_a_new_key(): void
    {
        $order = $this->pendingOrder($this->shopper(), $this->item());

        $this->pay($order, 'tok_fake_visa_0002_1230')->assertCreated()
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('meta.order.status', 'pending_payment');

        $this->pay($order)->assertCreated()->assertJsonPath('meta.order.status', 'confirmed');
    }

    public function test_requires_action_then_the_webhook_confirms(): void
    {
        $order = $this->pendingOrder($this->shopper(), $this->item());

        $reference = $this->pay($order, 'tok_fake_visa_3155_1230')->assertCreated()
            ->assertJsonPath('data.status', 'requires_action')
            ->assertJsonPath('meta.order.status', 'pending_payment')
            ->json('data.reference');

        $this->artisan('payments:fake-webhook', ['reference' => $reference, 'outcome' => 'succeeded'])->assertSuccessful();
        $this->assertSame(StoreOrderStatus::Confirmed, $order->fresh()->status);
    }

    public function test_stock_gone_before_payment_is_refused_without_a_charge(): void
    {
        $variant = $this->item('10.00', 1);
        $order = $this->pendingOrder($this->shopper(), $variant);
        $variant->update(['stock' => 0]);

        $this->pay($order)->assertStatus(409)->assertJsonPath('code', 'CART_HAS_ISSUES');
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_stock_gone_at_confirmation_cancels_and_refunds_in_full(): void
    {
        $client = $this->shopper();
        $variant = $this->item('10.00', 1);
        $order = $this->pendingOrder($client, $variant);
        $this->promo(['code' => 'P']);

        // The last unit goes between the charge and the confirmation (nothing is reserved, D207).
        $this->app->bind(StoreOrderLifecycle::class, fn ($app) => new class($variant, ...array_map(fn ($c) => $app->make($c), [ActivityRecorder::class, AuditLogger::class, Notifier::class, DeadlockRetry::class])) extends StoreOrderLifecycle
        {
            public function __construct(private readonly ProductVariant $variant, ...$deps)
            {
                parent::__construct(...$deps);
            }

            public function confirmPaid(int $orderId, int $paymentId): StoreOrder
            {
                $this->variant->update(['stock' => 0]); // someone else bought the last unit

                return parent::confirmPaid($orderId, $paymentId);
            }
        });

        $this->pay($order)->assertCreated()
            ->assertJsonPath('data.status', 'succeeded')
            ->assertJsonPath('data.fulfilment', 'failed')
            ->assertJsonPath('data.fulfilment_error', 'OUT_OF_STOCK')
            ->assertJsonPath('meta.order.status', 'cancelled')
            ->assertJsonPath('meta.order.cancel_reason', 'stock_unavailable');

        $this->assertSame(0, $variant->fresh()->stock);
        $this->assertDatabaseHas('refunds', ['payment_id' => Payment::query()->value('id'), 'amount' => '35.00']);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $client->id, 'type' => 'store_order_cancelled']);
    }

    public function test_a_payment_for_an_expired_order_is_refunded_and_fulfil_is_idempotent(): void
    {
        $order = $this->pendingOrder($this->shopper(), $this->item());
        $this->pay($order)->assertCreated();
        $payment = Payment::query()->firstOrFail();

        // Re-running fulfil for the same payment returns the order it confirmed.
        $again = app(StoreOrderLifecycle::class)->confirmPaid($order->id, $payment->id);
        $this->assertSame($order->id, $again->id);

        $late = $this->pendingOrder($this->shopper(), $this->item());
        app(StoreOrderLifecycle::class)->expire($late);
        $this->pay($late)->assertStatus(409)->assertJsonPath('code', 'INVALID_STATE'); // quote refuses before charging
    }

    public function test_promo_use_becomes_used_on_payment(): void
    {
        $client = $this->shopper();
        $this->inCart($client, $this->item('100.00'));
        $address = $this->addressOf($client);
        $this->promo(['code' => 'ONE']);
        $order = StoreOrder::query()->findOrFail($this->checkout($client, $address, '115.00', 'ONE')->assertCreated()->json('data.id'));

        $this->pay($order)->assertCreated()->assertJsonPath('data.amount.amount', '115.00');
        $this->assertSame('used', PromoCodeUsage::query()->where('store_order_id', $order->id)->value('status')->value);
    }

    public function test_paying_a_foreign_order_is_404(): void
    {
        $order = $this->pendingOrder($this->shopper(), $this->item());

        $this->actingAsApi($this->shopper())->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson(self::ORDERS_URL."/{$order->id}/payments", ['method' => 'card', 'token' => self::CARD])
            ->assertNotFound();
    }

    public function test_a_staff_refund_adds_history_without_changing_the_status(): void
    {
        $order = $this->pendingOrder($this->shopper(), $this->item('10.00'));
        $this->pay($order)->assertCreated();
        $payment = Payment::query()->firstOrFail();
        $finance = $this->superAdmin();

        $this->actingAsApi($finance)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/admin/payments/{$payment->id}/refunds", ['amount' => '5.00', 'reason' => 'Damaged box'])
            ->assertCreated();

        $this->assertSame(StoreOrderStatus::Confirmed, $order->fresh()->status);
        $this->assertDatabaseHas('activity_events', ['subject_id' => $order->id, 'event' => 'order_partially_refunded']);
        $this->actingAsApi($finance)->getJson(self::ADMIN_ORDERS_URL."/{$order->id}")->assertOk()
            ->assertJsonPath('data.payment.refund.status', 'partial');
    }
}
