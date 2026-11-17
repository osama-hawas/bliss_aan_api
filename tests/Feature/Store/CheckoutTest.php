<?php

namespace Tests\Feature\Store;

use App\Enums\StoreOrderStatus;
use App\Models\ActivityEvent;
use App\Models\PromoCodeUsage;
use App\Models\StoreOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsStoreOrders;
use Tests\TestCase;

/**
 * Slice 9b checkout: the unpaid order snapshot (`slice-9b-contract.md` §6.2, D207, D210, D212).
 */
class CheckoutTest extends TestCase
{
    use BuildsStoreOrders, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureStore('25.00');
        $this->referencesEnding();
    }

    public function test_checkout_creates_a_pending_snapshot_and_reserves_nothing(): void
    {
        $client = $this->shopper(['phone' => '+966500000777']);
        $variant = $this->item('40.00', 5, ['attributes' => ['size' => ['en' => 'M', 'ar' => 'م']]]);
        $this->inCart($client, $variant, 2);
        $address = $this->addressOf($client, ['recipient_name' => 'Mona']);

        $response = $this->checkout($client, $address, '105.00')->assertCreated()
            ->assertJsonPath('data.reference', 'SO-000001')
            ->assertJsonPath('data.status', 'pending_payment')
            ->assertJsonPath('data.total.amount', '105.00')
            ->assertJsonPath('data.items.0.quantity', 2)
            ->assertJsonPath('data.items.0.options.0.value', 'M')
            ->assertJsonPath('data.recipient.name', 'Mona')
            ->assertJsonPath('data.recipient.phone', '+966500000777')
            ->assertJsonPath('data.address.city', 'Riyadh')
            ->assertJsonPath('data.can_pay', true)
            ->assertJsonPath('data.payment', null)
            ->assertJsonPath('data.timeline', []); // the placed event is internal

        $this->assertSame(5, $variant->fresh()->stock); // D207: nothing deducted before payment
        $this->assertDatabaseCount('cart_items', 1);   // the cart is emptied only when paid

        // Snapshot: later price / address changes don't touch the order.
        $variant->update(['price' => '99.00']);
        $address->update(['details' => 'Moved']);
        $this->actingAsApi($client)->getJson(self::ORDERS_URL.'/'.$response->json('data.id'))->assertOk()
            ->assertJsonPath('data.items.0.unit_price.amount', '40.00')
            ->assertJsonPath('data.address.details', 'Street 1, house 2');
        $this->assertSame('internal', ActivityEvent::query()->where('event', 'order_placed')->value('visibility')->value);
    }

    public function test_the_same_key_replays_and_another_body_is_a_duplicate(): void
    {
        $client = $this->shopper();
        $this->inCart($client, $this->item('10.00'));
        $address = $this->addressOf($client);
        $key = (string) Str::uuid();

        $id = $this->checkout($client, $address, '35.00', key: $key)->assertCreated()->json('data.id');
        $this->checkout($client, $address, '35.00', key: $key)->assertOk()->assertJsonPath('data.id', $id);
        $this->checkout($client, $address, '36.00', key: $key)->assertStatus(409)->assertJsonPath('code', 'DUPLICATE_REQUEST');
        $this->assertDatabaseCount('store_orders', 1);

        $this->actingAsApi($client)->postJson(self::ORDERS_URL, ['address_id' => $address->id, 'expected_total' => '35.00'])
            ->assertStatus(422)->assertJsonValidationErrors('idempotency_key');
    }

    public function test_a_changed_total_is_409_price_changed_with_the_new_preview(): void
    {
        $client = $this->shopper();
        $variant = $this->item('10.00');
        $this->inCart($client, $variant);
        $address = $this->addressOf($client);
        $variant->update(['price' => '12.00']);

        $this->checkout($client, $address, '35.00')->assertStatus(409)
            ->assertJsonPath('code', 'PRICE_CHANGED')
            ->assertJsonPath('data.total.amount', '37.00')
            ->assertJsonPath('data.price_changes.0.previous_unit_price.amount', '10.00');
        $this->assertDatabaseCount('store_orders', 0);

        $this->checkout($client, $address, '37.00')->assertCreated();
    }

    public function test_refusals_create_nothing(): void
    {
        $client = $this->shopper();
        $address = $this->addressOf($client);
        $this->checkout($client, $address, '0.00')->assertStatus(422)->assertJsonPath('code', 'CART_EMPTY');

        $variant = $this->item('10.00', 1);
        $this->inCart($client, $variant);
        $variant->update(['stock' => 0]);
        $this->checkout($client, $address, '35.00')->assertStatus(409)->assertJsonPath('code', 'CART_HAS_ISSUES');

        $variant->update(['stock' => 1]);
        $this->configureStore(null);
        $this->checkout($client, $address, '35.00')->assertStatus(409)->assertJsonPath('code', 'NOT_CONFIGURED');

        $this->configureStore('25.00');
        $this->checkout($client, $this->addressOf($this->shopper()), '35.00')->assertNotFound();
        $this->assertDatabaseCount('store_orders', 0);
    }

    public function test_a_promo_use_is_held_and_a_newer_checkout_expires_the_older_order(): void
    {
        $client = $this->shopper();
        $this->inCart($client, $this->item('100.00'));
        $address = $this->addressOf($client);
        $promo = $this->promo(['code' => 'ONE', 'per_user_limit' => 1]);

        $first = $this->checkout($client, $address, '115.00', 'ONE')->assertCreated()
            ->assertJsonPath('data.discount.amount', '10.00')
            ->assertJsonPath('data.promo.code', 'ONE')->json('data.id');
        $this->assertSame('held', PromoCodeUsage::query()->where('store_order_id', $first)->value('status')->value);

        // Her second checkout replaces the first (one open order per client), which frees her single use.
        $second = $this->checkout($client, $address, '115.00', 'ONE')->assertCreated()->json('data.id');
        $this->assertSame(StoreOrderStatus::Expired, StoreOrder::query()->find($first)->status);
        $this->assertSame('released', PromoCodeUsage::query()->where('store_order_id', $first)->value('status')->value);
        $this->assertSame('held', PromoCodeUsage::query()->where('store_order_id', $second)->value('status')->value);
        $this->assertSame(1, $promo->usages()->whereIn('status', ['held', 'used'])->count());
    }

    public function test_a_refused_checkout_does_not_expire_her_open_order(): void
    {
        $client = $this->shopper();
        $variant = $this->item('10.00');
        $this->inCart($client, $variant);
        $address = $this->addressOf($client);
        $first = $this->checkout($client, $address, '35.00')->assertCreated()->json('data.id');

        $this->checkout($client, $address, '1.00')->assertStatus(409)->assertJsonPath('code', 'PRICE_CHANGED');
        $this->assertSame(StoreOrderStatus::PendingPayment, StoreOrder::query()->find($first)->status);
    }

    public function test_pending_and_expired_orders_are_not_in_her_list_but_can_be_opened(): void
    {
        $client = $this->shopper();
        $order = $this->pendingOrder($client, $this->item());

        $this->actingAsApi($client)->getJson(self::ORDERS_URL)->assertOk()->assertJsonCount(0, 'data');
        $this->actingAsApi($client)->getJson(self::ORDERS_URL.'/'.$order->id)->assertOk()->assertJsonPath('data.status', 'pending_payment');
        $this->actingAsApi($this->shopper())->getJson(self::ORDERS_URL.'/'.$order->id)->assertNotFound();
    }
}
