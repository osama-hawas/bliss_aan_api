<?php

namespace Tests\Feature\Store;

use App\Enums\StoreOrderStatus;
use App\Models\PromoCodeUsage;
use App\Models\StoreOrder;
use App\Services\Settings\AppSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsStoreOrders;
use Tests\TestCase;

/**
 * Slice 9b expiry of unpaid orders (`slice-9b-contract.md` §7.4, D212/Q115).
 */
class StoreOrderExpiryTest extends TestCase
{
    use BuildsStoreOrders, RefreshDatabase;

    public function test_old_unpaid_orders_without_an_open_attempt_expire_and_release_their_promo_use(): void
    {
        $this->configureStore('25.00');
        app(AppSettings::class)->set('payments.intent_ttl_minutes', 30);
        $client = $this->shopper();
        $this->inCart($client, $this->item('100.00'));
        $this->promo(['code' => 'P']);
        $address = $this->addressOf($client);
        $old = $this->checkout($client, $address, '115.00', 'P')->assertCreated()->json('data.id');

        $this->travel(31)->minutes();
        $fresh = $this->bareOrder($this->shopper(), StoreOrderStatus::PendingPayment);

        $this->artisan('store-orders:expire')->assertSuccessful();

        $this->assertSame(StoreOrderStatus::Expired, StoreOrder::query()->find($old)->status);
        $this->assertSame(StoreOrderStatus::PendingPayment, $fresh->fresh()->status);
        $this->assertSame('released', PromoCodeUsage::query()->where('store_order_id', $old)->value('status')->value);
        $this->actingAsApi($client)->getJson(self::ORDERS_URL."/{$old}")->assertOk()
            ->assertJsonPath('data.status', 'expired')->assertJsonPath('data.can_pay', false);
    }

    public function test_no_lifetime_means_nothing_expires_by_time(): void
    {
        app(AppSettings::class)->set('payments.intent_ttl_minutes', null);
        $order = $this->bareOrder($this->shopper(), StoreOrderStatus::PendingPayment);
        $this->travel(2)->days();

        $this->artisan('store-orders:expire')->assertSuccessful();
        $this->assertSame(StoreOrderStatus::PendingPayment, $order->fresh()->status);
    }
}
