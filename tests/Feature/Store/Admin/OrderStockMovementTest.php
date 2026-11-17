<?php

namespace Tests\Feature\Store\Admin;

use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Models\StockMovement;
use App\Models\StoreOrder;
use App\Services\Store\StoreOrderLifecycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsStoreOrders;
use Tests\TestCase;

/**
 * S18 fix round (M1, D279): S9b's real deduction at confirmation (`StoreOrderLifecycle::confirmPaid`, through the Slice 10
 * payment core) and restoration at staff cancel (`cancelByStaff`) write `order` / `order_cancelled` stock movements.
 */
class OrderStockMovementTest extends TestCase
{
    use BuildsStoreOrders, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureStore('25.00');
        $this->referencesEnding();
    }

    public function test_payment_and_staff_cancel_are_in_the_stock_history(): void
    {
        $client = $this->shopper();
        $variant = $this->item('40.00', 5);
        $order = $this->pendingOrder($client, $variant, 2);

        $this->actingAsApi($client)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson(self::ORDERS_URL."/{$order->id}/payments", ['method' => 'card', 'token' => 'tok_fake_visa_4242_1230'])
            ->assertCreated()->assertJsonPath('meta.order.status', 'confirmed');

        $this->assertSame(3, $variant->fresh()->stock);
        $sale = StockMovement::query()->sole();
        $this->assertSame('order', $sale->source->value);
        $this->assertSame([-2, 5, 3, $order->id, null], [$sale->change, $sale->stock_before, $sale->stock_after, $sale->store_order_id, $sale->actor_id]);

        $staff = $this->storeOfficer();
        $this->actingAsApi($staff)->postJson(self::ADMIN_ORDERS_URL."/{$order->id}/cancel", ['reason' => 'Client asked by phone'])->assertOk();

        $this->assertSame(5, $variant->fresh()->stock);
        $back = StockMovement::query()->latest('id')->first();
        $this->assertSame('order_cancelled', $back->source->value);
        $this->assertSame([2, 3, 5, $order->id, $staff->id], [$back->change, $back->stock_before, $back->stock_after, $back->store_order_id, $back->actor_id]);

        $this->actingAsApi($staff)->getJson("/api/v1/admin/products/{$variant->product_id}/stock-movements")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.source', 'order_cancelled')
            ->assertJsonPath('data.0.store_order_id', $order->id)
            ->assertJsonPath('data.1.source', 'order');
    }

    public function test_a_stock_shortfall_at_confirmation_writes_no_movement(): void
    {
        $client = $this->shopper();
        $variant = $this->item('40.00', 2);
        $order = $this->pendingOrder($client, $variant, 2);
        $variant->update(['stock' => 1]);

        // The payment API's quote() refuses before charging; this is the late case where stock went after the charge.
        try {
            app(StoreOrderLifecycle::class)->confirmPaid($order->id, 999_001);
            $this->fail('A short order was confirmed.');
        } catch (ApiException $e) {
            $this->assertSame(ErrorCode::OutOfStock, $e->errorCode);
        }

        $this->assertSame('cancelled', StoreOrder::query()->find($order->id)->status->value);
        $this->assertDatabaseCount('stock_movements', 0);
    }
}
