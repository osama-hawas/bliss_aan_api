<?php

namespace Tests\Feature\Database;

use App\Enums\ErrorCode;
use App\Enums\StoreOrderStatus;
use App\Exceptions\ApiException;
use App\Services\Store\CheckoutService;
use App\Services\Store\StoreOrderLifecycle;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsStoreOrders;
use Tests\Concerns\UsesCommittedRows;
use Tests\TestCase;

/**
 * Slice 9b locks with two real MySQL sessions (`slice-9b-contract.md` §6.2, §7.3): the other session holds the row the
 * race is about; this session must wait (1 s lock wait timeout), then decide on what the other one committed.
 * Lock order: order → variants (id asc) → cart lines → promo code.
 */
class StoreOrderLockingTest extends TestCase
{
    use BuildsStoreOrders, UsesCommittedRows;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCommittedRows();
        $this->configureStore('25.00');
        $this->referencesEnding();
    }

    protected function tearDown(): void
    {
        $this->tearDownCommittedRows();
        parent::tearDown();
    }

    private function other()
    {
        return DB::connection('other_session');
    }

    private function assertApiError(ErrorCode $code, callable $call): void
    {
        try {
            $call();
            $this->fail("Expected {$code->value}.");
        } catch (ApiException $e) {
            $this->assertSame($code, $e->errorCode);
        }
    }

    public function test_the_last_promo_use_cannot_be_taken_twice(): void
    {
        $promo = $this->promo(['code' => 'LAST', 'usage_limit' => 1]);
        $rival = $this->shopper();
        $rivalOrder = $this->bareOrder($rival, StoreOrderStatus::PendingPayment);
        $client = $this->shopper();
        $this->inCart($client, $this->item('100.00'));
        $address = $this->addressOf($client);

        // The other session's checkout holds the promo row and takes the last use.
        $this->other()->beginTransaction();
        $this->other()->select('select id from promo_codes where id = ? for update', [$promo->id]);
        $this->other()->table('promo_code_usages')->insert([
            'promo_code_id' => $promo->id, 'user_id' => $rival->id, 'store_order_id' => $rivalOrder->id, 'status' => 'held',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $create = fn () => app(CheckoutService::class)->create($client, (string) Str::uuid(), ['address_id' => $address->id, 'promo_code' => 'LAST', 'expected_total' => '115.00']);

        $this->shortLockWait();
        $this->assertWaits($create);
        $this->other()->commit();

        $this->assertApiError(ErrorCode::PromoCodeInvalid, $create);
        $this->assertSame(1, $promo->usages()->count());
    }

    public function test_confirmation_waits_for_the_variant_and_cancels_when_the_last_unit_is_gone(): void
    {
        $variant = $this->item('10.00', 1);
        $order = $this->pendingOrder($this->shopper(), $variant);

        // Another order's confirmation holds the variant and takes the last unit.
        $this->other()->beginTransaction();
        $this->other()->select('select id from product_variants where id = ? for update', [$variant->id]);
        $this->other()->table('product_variants')->where('id', $variant->id)->update(['stock' => 0]);

        $confirm = fn () => app(StoreOrderLifecycle::class)->confirmPaid($order->id, 999_001);

        $this->shortLockWait();
        $this->assertWaits($confirm);
        $this->other()->commit();

        $this->assertApiError(ErrorCode::OutOfStock, $confirm);
        $this->assertSame(StoreOrderStatus::Cancelled, $order->fresh()->status);
        $this->assertSame(0, $variant->fresh()->stock);
    }

    public function test_staff_cancel_waits_for_the_order_row(): void
    {
        $order = $this->confirmedOrder();
        $staff = $this->storeOfficer();

        // A refund (lockForRefund) or another staff action holds the order first.
        $this->other()->beginTransaction();
        $this->other()->select('select id from store_orders where id = ? for update', [$order->id]);
        $this->other()->table('store_orders')->where('id', $order->id)->update(['status' => 'preparing']);

        $cancel = fn () => app(StoreOrderLifecycle::class)->cancelByStaff($order, $staff, 'Out of stock at the warehouse');

        $this->shortLockWait();
        $this->assertWaits($cancel);
        $this->other()->commit();

        $cancel();
        $this->assertSame(StoreOrderStatus::Cancelled, $order->fresh()->status);
    }
}
