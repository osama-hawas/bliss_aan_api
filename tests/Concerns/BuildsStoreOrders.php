<?php

namespace Tests\Concerns;

use App\Enums\AdminRole;
use App\Enums\PromoCodeType;
use App\Enums\StoreOrderStatus;
use App\Models\Address;
use App\Models\CartItem;
use App\Models\City;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Models\PromoCode;
use App\Models\StoreOrder;
use App\Models\User;
use App\Services\Settings\AppSettings;
use App\Services\Store\StoreOrderReferenceGenerator;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * Slice 9b helpers: a configured store, her address and cart, checkout through the API, and a confirmed order without
 * going through the Slice 10 gateway (for order / shipment tests that are not about payment).
 */
trait BuildsStoreOrders
{
    use BuildsStaff;

    protected const ORDERS_URL = '/api/v1/client/store-orders';

    protected const ADMIN_ORDERS_URL = '/api/v1/admin/store-orders';

    protected function configureStore(?string $fee = '25.00', ?string $vat = null, ?int $maxPerLine = null): void
    {
        $settings = app(AppSettings::class);
        $settings->set('store.shipping_fee', $fee);
        $settings->set('tax.vat_percent', $vat);
        $settings->set('store.max_qty_per_line', $maxPerLine);
    }

    /** Predictable references; `$suffix` drives the fake carrier (`-FAIL`, `-DELIVER`). */
    protected function referencesEnding(string $suffix = ''): void
    {
        $this->app->instance(StoreOrderReferenceGenerator::class, new class($suffix) extends StoreOrderReferenceGenerator
        {
            private int $n = 0;

            public function __construct(private readonly string $suffix) {}

            public function next(): string
            {
                return 'SO-'.str_pad((string) ++$this->n, 6, '0', STR_PAD_LEFT).$this->suffix;
            }
        });
    }

    protected function shopper(array $attributes = []): User
    {
        return User::factory()->client()->create(['name' => 'Sara Client', ...$attributes]);
    }

    protected function addressOf(User $client, array $attributes = []): Address
    {
        $city = City::query()->first() ?? City::query()->create(['name' => ['en' => 'Riyadh', 'ar' => 'الرياض'], 'is_active' => true, 'sort' => 1]);

        return Address::query()->create([
            'user_id' => $client->id,
            'city_id' => $city->id,
            'district' => 'Al Olaya',
            'details' => 'Street 1, house 2',
            ...$attributes,
        ]);
    }

    protected function item(string $price = '50.00', int $stock = 10, array $attributes = []): ProductVariant
    {
        $category = ProductCategory::factory()->create(['is_active' => true]);
        $product = Product::factory()->create(['category_id' => $category->id, 'is_active' => true]);

        return ProductVariant::factory()->create(['product_id' => $product->id, 'price' => $price, 'stock' => $stock, 'is_active' => true, ...$attributes]);
    }

    protected function inCart(User $client, ProductVariant $variant, int $quantity = 1): void
    {
        $this->actingAsApi($client)->putJson("/api/v1/client/cart/items/{$variant->id}", ['quantity' => $quantity])->assertOk();
    }

    protected function promo(array $attributes = []): PromoCode
    {
        return PromoCode::query()->create(['code' => 'SAVE10', 'type' => PromoCodeType::Percent, 'value' => '10.00', 'is_active' => true, ...$attributes]);
    }

    protected function preview(User $client, array $body = []): TestResponse
    {
        return $this->actingAsApi($client)->postJson(self::ORDERS_URL.'/preview', $body);
    }

    protected function checkout(User $client, Address $address, string $expectedTotal, ?string $promo = null, ?string $key = null): TestResponse
    {
        return $this->actingAsApi($client)
            ->withHeader('Idempotency-Key', $key ?? (string) Str::uuid())
            ->postJson(self::ORDERS_URL, array_filter(['address_id' => $address->id, 'promo_code' => $promo, 'expected_total' => $expectedTotal], fn ($v) => $v !== null));
    }

    /** A pending order created through the API (cart: one line of `$variant`). */
    protected function pendingOrder(User $client, ProductVariant $variant, int $quantity = 1): StoreOrder
    {
        $this->inCart($client, $variant, $quantity);
        $address = $this->addressOf($client);
        $total = $this->preview($client, ['address_id' => $address->id])->assertOk()->json('data.total.amount');
        $id = $this->checkout($client, $address, $total)->assertCreated()->json('data.id');

        return StoreOrder::query()->findOrFail($id);
    }

    /**
     * A placed order without the Slice 10 gateway: what the payment handler does (stock deducted, status confirmed), with
     * no payment row. For order and shipment tests only.
     */
    protected function confirmedOrder(?User $client = null, ?ProductVariant $variant = null, int $quantity = 1): StoreOrder
    {
        $client ??= $this->shopper();
        $variant ??= $this->item();
        $order = $this->pendingOrder($client, $variant, $quantity);
        $variant->decrement('stock', $quantity);
        CartItem::query()->whereHas('cart', fn ($cart) => $cart->where('user_id', $client->id))->delete();
        $order->forceFill(['status' => StoreOrderStatus::Confirmed, 'confirmed_at' => now()])->save();

        return $order->refresh();
    }

    /** A bare order row (for promo-use counting); no items. */
    protected function bareOrder(User $client, StoreOrderStatus $status = StoreOrderStatus::Confirmed): StoreOrder
    {
        return StoreOrder::query()->create([
            'reference' => 'SO-'.Str::upper(Str::random(7)),
            'user_id' => $client->id,
            'status' => $status,
            'address' => ['city' => ['en' => 'Riyadh', 'ar' => 'الرياض'], 'details' => 'x'],
            'recipient_name' => $client->name,
            'recipient_phone' => $client->phone,
            'subtotal' => '10.00', 'discount' => '0.00', 'shipping_fee' => '0.00', 'total' => '10.00',
            'idempotency_key' => (string) Str::uuid(),
            'idempotency_fingerprint' => str_repeat('0', 64),
        ]);
    }

    protected function storeOfficer(array $attributes = []): User
    {
        return $this->member(AdminRole::StoreContentOfficer, $attributes);
    }

    protected function deliveryOfficer(array $attributes = []): User
    {
        return $this->member(AdminRole::DeliveryOfficer, $attributes);
    }
}
