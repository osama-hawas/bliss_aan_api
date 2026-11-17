<?php

namespace Tests\Feature\Store;

use App\Enums\StoreOrderStatus;
use App\Models\CartItem;
use App\Models\PromoCodeUsage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsStoreOrders;
use Tests\TestCase;

/**
 * Slice 9b checkout preview (`slice-9b-contract.md` §6.1, D200–D202, D205, D210, D213). Writes nothing.
 */
class CheckoutPreviewTest extends TestCase
{
    use BuildsStoreOrders, RefreshDatabase;

    public function test_only_clients_can_preview(): void
    {
        $this->postJson(self::ORDERS_URL.'/preview')->assertUnauthorized();
        $this->actingAsApi($this->storeOfficer())->postJson(self::ORDERS_URL.'/preview')->assertForbidden();
    }

    public function test_totals_with_shipping_and_no_vat_line_when_vat_is_unset(): void
    {
        $this->configureStore('25.00');
        $client = $this->shopper();
        $this->inCart($client, $this->item('40.00'), 2);
        $address = $this->addressOf($client);

        $this->preview($client, ['address_id' => $address->id])->assertOk()
            ->assertJsonPath('data.subtotal.amount', '80.00')
            ->assertJsonPath('data.discount.amount', '0.00')
            ->assertJsonPath('data.shipping_fee.amount', '25.00')
            ->assertJsonPath('data.total.amount', '105.00')
            ->assertJsonPath('data.vat', null)
            ->assertJsonPath('data.promo', null)
            ->assertJsonPath('data.address.id', $address->id)
            ->assertJsonPath('data.can_checkout', true)
            ->assertJsonCount(1, 'data.lines');
        $this->assertDatabaseCount('store_orders', 0);
    }

    public function test_vat_is_shown_as_included_when_configured(): void
    {
        $this->configureStore('15.00', '15');
        $client = $this->shopper();
        $this->inCart($client, $this->item('100.00'));

        // total 115.00 → VAT included = 115 × 15 / 115 = 15.00
        $this->preview($client)->assertOk()
            ->assertJsonPath('data.total.amount', '115.00')
            ->assertJsonPath('data.vat.percent', '15.00')
            ->assertJsonPath('data.vat.amount.amount', '15.00')
            ->assertJsonPath('data.can_checkout', false); // no address chosen yet
    }

    public function test_shipping_fee_not_configured_is_409(): void
    {
        $this->configureStore(null);
        $client = $this->shopper();
        $this->inCart($client, $this->item());

        $this->preview($client)->assertStatus(409)->assertJsonPath('code', 'NOT_CONFIGURED');
    }

    public function test_empty_cart_and_flagged_lines_refuse_checkout(): void
    {
        $this->configureStore();
        $client = $this->shopper();
        $this->preview($client)->assertStatus(422)->assertJsonPath('code', 'CART_EMPTY');

        $variant = $this->item('10.00', 3);
        $this->inCart($client, $variant, 3);
        $variant->update(['stock' => 1]);

        $this->preview($client)->assertStatus(409)->assertJsonPath('code', 'CART_HAS_ISSUES')
            ->assertJsonPath('errors.items', [(string) $variant->id]);

        $variant->update(['stock' => 5, 'is_active' => false]);
        $this->preview($client)->assertStatus(409)->assertJsonPath('code', 'CART_HAS_ISSUES');
    }

    public function test_percent_and_fixed_promo_codes_on_the_products_subtotal_only(): void
    {
        $this->configureStore('25.00');
        $client = $this->shopper();
        $this->inCart($client, $this->item('33.33'), 1);

        $this->promo(['code' => 'TEN', 'value' => '10.00']);
        // 10 % of 33.33 = 3.333 → 3.33 (half up); shipping untouched.
        $this->preview($client, ['promo_code' => 'ten'])->assertOk()
            ->assertJsonPath('data.discount.amount', '3.33')
            ->assertJsonPath('data.promo.code', 'TEN')
            ->assertJsonPath('data.total.amount', '55.00');

        $this->promo(['code' => 'BIG', 'type' => 'fixed', 'value' => '50.00']);
        // A fixed code never discounts more than the subtotal.
        $this->preview($client, ['promo_code' => 'BIG'])->assertOk()
            ->assertJsonPath('data.discount.amount', '33.33')
            ->assertJsonPath('data.total.amount', '25.00');
        $this->assertDatabaseCount('promo_code_usages', 0);
    }

    public function test_promo_refusal_reasons(): void
    {
        $this->configureStore();
        $client = $this->shopper();
        $this->inCart($client, $this->item('50.00'));

        $this->preview($client, ['promo_code' => 'NOPE'])->assertStatus(422)->assertJsonPath('code', 'PROMO_CODE_INVALID')
            ->assertJsonPath('errors.promo_code.0', __('store.promo.unknown'));

        $this->promo(['code' => 'OFF', 'is_active' => false]);
        $this->preview($client, ['promo_code' => 'OFF'])->assertJsonPath('errors.promo_code.0', __('store.promo.unknown'));

        $this->promo(['code' => 'LATER', 'starts_at' => now()->addDay()]);
        $this->preview($client, ['promo_code' => 'LATER'])->assertJsonPath('errors.promo_code.0', __('store.promo.not_started'));

        $this->promo(['code' => 'GONE', 'ends_at' => now()->subMinute()]);
        $this->preview($client, ['promo_code' => 'GONE'])->assertJsonPath('errors.promo_code.0', __('store.promo.ended'));

        $this->promo(['code' => 'MIN', 'min_amount' => '100.00']);
        $this->preview($client, ['promo_code' => 'MIN'])->assertJsonPath('errors.promo_code.0', __('store.promo.min_amount', ['amount' => '100.00']));

        $limited = $this->promo(['code' => 'ONCE', 'usage_limit' => 1]);
        $someone = $this->shopper();
        PromoCodeUsage::query()->create(['promo_code_id' => $limited->id, 'user_id' => $someone->id, 'store_order_id' => $this->bareOrder($someone)->id, 'status' => 'used']);
        $this->preview($client, ['promo_code' => 'ONCE'])->assertJsonPath('errors.promo_code.0', __('store.promo.usage_limit'));

        $mine = $this->promo(['code' => 'MINE', 'per_user_limit' => 1]);
        PromoCodeUsage::query()->create(['promo_code_id' => $mine->id, 'user_id' => $client->id, 'store_order_id' => $this->bareOrder($client)->id, 'status' => 'held']);
        $this->preview($client, ['promo_code' => 'MINE'])->assertJsonPath('errors.promo_code.0', __('store.promo.user_limit'));

        $released = $this->promo(['code' => 'FREED', 'per_user_limit' => 1]);
        PromoCodeUsage::query()->create(['promo_code_id' => $released->id, 'user_id' => $client->id, 'store_order_id' => $this->bareOrder($client, StoreOrderStatus::Expired)->id, 'status' => 'released']);
        $this->preview($client, ['promo_code' => 'FREED'])->assertOk(); // a released use doesn't count
    }

    public function test_a_code_covering_the_whole_order_is_refused(): void
    {
        $this->configureStore('0.00');
        $client = $this->shopper();
        $this->inCart($client, $this->item('20.00'));
        $this->promo(['code' => 'ALL', 'value' => '100.00']);

        $this->preview($client, ['promo_code' => 'ALL'])->assertStatus(422)
            ->assertJsonPath('errors.promo_code.0', __('store.promo.covers_order'));
    }

    public function test_price_changes_since_the_line_was_set_are_listed(): void
    {
        $this->configureStore();
        $client = $this->shopper();
        $variant = $this->item('20.00');
        $this->inCart($client, $variant);
        $variant->update(['price' => '22.00']);

        $this->preview($client)->assertOk()
            ->assertJsonPath('data.price_changes.0.variant_id', $variant->id)
            ->assertJsonPath('data.price_changes.0.previous_unit_price.amount', '20.00')
            ->assertJsonPath('data.price_changes.0.unit_price.amount', '22.00')
            ->assertJsonPath('data.subtotal.amount', '22.00');

        $this->actingAsApi($client)->getJson('/api/v1/client/cart')->assertOk()
            ->assertJsonPath('data.price_changed', true)
            ->assertJsonPath('data.items.0.price_changed', true)
            ->assertJsonPath('data.items.0.previous_unit_price.amount', '20.00');

        $this->inCart($client, $variant, 2); // setting the quantity again: she saw the new price
        $this->assertSame('22.00', CartItem::query()->first()->added_unit_price);
    }

    public function test_a_foreign_address_is_404(): void
    {
        $this->configureStore();
        $client = $this->shopper();
        $this->inCart($client, $this->item());
        $other = $this->addressOf($this->shopper());

        $this->preview($client, ['address_id' => $other->id])->assertNotFound();
    }
}
