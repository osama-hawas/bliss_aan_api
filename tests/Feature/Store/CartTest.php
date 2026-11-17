<?php

namespace Tests\Feature\Store;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Settings\AppSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CartTest extends TestCase
{
    use RefreshDatabase;

    private function client(): User
    {
        return User::factory()->client()->create();
    }

    private function variant(array $attributes = []): ProductVariant
    {
        return ProductVariant::factory()->create($attributes);
    }

    public function test_guests_providers_and_admins_cannot_use_a_cart(): void
    {
        $variant = $this->variant();

        $this->getJson('/api/v1/client/cart')->assertUnauthorized();
        $this->putJson("/api/v1/client/cart/items/{$variant->id}", ['quantity' => 1])->assertUnauthorized();
        $this->actingAsApi(User::factory()->provider()->create())->getJson('/api/v1/client/cart')->assertForbidden();
        $this->actingAsApi(User::factory()->admin()->create())->putJson("/api/v1/client/cart/items/{$variant->id}", ['quantity' => 1])->assertForbidden();
        $this->actingAsApi(User::factory()->client()->suspended()->create())->getJson('/api/v1/client/cart')->assertForbidden()->assertJsonPath('code', 'ACCOUNT_SUSPENDED');
        $this->assertDatabaseCount('carts', 0);
    }

    public function test_an_empty_cart_is_returned_before_the_first_item(): void
    {
        $this->actingAsApi($this->client())->getJson('/api/v1/client/cart')
            ->assertOk()
            ->assertJsonPath('data.items', [])
            ->assertJsonPath('data.count', 0)
            ->assertJsonPath('data.subtotal', ['amount' => '0.00', 'currency' => 'SAR'])
            ->assertJsonPath('data.has_issues', false);
        $this->assertDatabaseCount('carts', 0);
    }

    public function test_set_adds_then_replaces_the_quantity_and_sums_live_prices(): void
    {
        $client = $this->client();
        $a = $this->variant(['price' => '12.50', 'stock' => 5, 'attributes' => ['size' => ['en' => 'S', 'ar' => 'ص']]]);
        $b = $this->variant(['price' => '100.00', 'stock' => 1]);

        $this->actingAsApi($client)->putJson("/api/v1/client/cart/items/{$a->id}", ['quantity' => 2])
            ->assertOk()->assertJsonPath('data.count', 2)->assertJsonPath('data.subtotal.amount', '25.00');
        $this->actingAsApi($client)->putJson("/api/v1/client/cart/items/{$a->id}", ['quantity' => 3])
            ->assertOk()->assertJsonPath('data.items.0.quantity', 3)->assertJsonPath('data.items.0.line_total.amount', '37.50');
        $this->actingAsApi($client)->putJson("/api/v1/client/cart/items/{$a->id}", ['quantity' => 3])->assertOk(); // idempotent
        $response = $this->actingAsApi($client)->putJson("/api/v1/client/cart/items/{$b->id}", ['quantity' => 1])
            ->assertOk()
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.count', 4)
            ->assertJsonPath('data.subtotal.amount', '137.50')
            ->assertJsonPath('data.items.0.variant_id', $a->id)
            ->assertJsonPath('data.items.0.product_id', $a->product_id)
            ->assertJsonPath('data.items.0.unit_price.amount', '12.50')
            ->assertJsonPath('data.items.0.available', true)
            ->assertJsonPath('data.items.0.exceeds_stock', false)
            ->assertJsonStructure(['data' => ['items' => [['variant_id', 'product_id', 'name', 'category', 'image_url', 'attributes', 'unit_price', 'quantity', 'line_total', 'available', 'in_stock', 'exceeds_stock']], 'count', 'subtotal', 'has_issues']]);
        $this->assertSame(1, Cart::query()->count());
        $this->assertSame(2, CartItem::query()->count());

        // Nothing is reserved or deducted.
        $this->assertSame(5, $a->fresh()->stock);
        $this->assertSame(1, $b->fresh()->stock);
        $response->assertJsonMissingPath('data.discount')->assertJsonMissingPath('data.shipping_fee');
    }

    public function test_a_quantity_above_current_stock_is_refused(): void
    {
        $client = $this->client();
        $variant = $this->variant(['stock' => 2]);
        $soldOut = $this->variant(['stock' => 0]);

        $this->actingAsApi($client)->putJson("/api/v1/client/cart/items/{$variant->id}", ['quantity' => 3])
            ->assertStatus(422)->assertJsonPath('code', 'OUT_OF_STOCK')->assertJsonStructure(['errors' => ['quantity']])
            ->assertJsonMissingPath('errors.available');
        $this->actingAsApi($client)->putJson("/api/v1/client/cart/items/{$soldOut->id}", ['quantity' => 1])
            ->assertStatus(422)->assertJsonPath('code', 'OUT_OF_STOCK');
        $this->actingAsApi($client)->putJson("/api/v1/client/cart/items/{$variant->id}", ['quantity' => 2])->assertOk();
        $this->assertDatabaseCount('cart_items', 1);
    }

    public function test_invalid_quantities_and_unavailable_variants(): void
    {
        $client = $this->client();
        $variant = $this->variant();
        $inactive = $this->variant(['is_active' => false]);
        $hiddenProduct = $this->variant(['product_id' => Product::factory()->inactive()->create()->id]);
        $hiddenCategory = $this->variant(['product_id' => Product::factory()->create(['category_id' => ProductCategory::factory()->inactive()->create()->id])->id]);

        foreach ([0, -1, 'two', null, 1.5] as $quantity) {
            $this->actingAsApi($client)->putJson("/api/v1/client/cart/items/{$variant->id}", ['quantity' => $quantity])
                ->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_FAILED');
        }
        foreach ([$inactive->id, $hiddenProduct->id, $hiddenCategory->id, 999999] as $id) {
            $this->actingAsApi($client)->putJson("/api/v1/client/cart/items/{$id}", ['quantity' => 1])->assertNotFound()->assertJsonPath('code', 'NOT_FOUND');
        }
        $this->assertDatabaseCount('cart_items', 0);
    }

    public function test_remove_and_clear_are_idempotent_and_scoped_to_her_cart(): void
    {
        $her = $this->client();
        $other = $this->client();
        $a = $this->variant();
        $b = $this->variant();

        $this->actingAsApi($her)->putJson("/api/v1/client/cart/items/{$a->id}", ['quantity' => 1])->assertOk();
        $this->actingAsApi($her)->putJson("/api/v1/client/cart/items/{$b->id}", ['quantity' => 1])->assertOk();
        $this->actingAsApi($other)->putJson("/api/v1/client/cart/items/{$a->id}", ['quantity' => 2])->assertOk();

        $this->actingAsApi($her)->deleteJson("/api/v1/client/cart/items/{$a->id}")->assertOk()->assertJsonCount(1, 'data.items');
        $this->actingAsApi($her)->deleteJson("/api/v1/client/cart/items/{$a->id}")->assertOk()->assertJsonCount(1, 'data.items');
        $this->actingAsApi($her)->deleteJson('/api/v1/client/cart')->assertOk()->assertJsonPath('data.items', [])->assertJsonPath('data.count', 0);
        $this->actingAsApi($her)->deleteJson('/api/v1/client/cart')->assertOk();
        $this->actingAsApi(User::factory()->client()->create())->deleteJson('/api/v1/client/cart')->assertOk(); // no cart yet

        $this->actingAsApi($other)->getJson('/api/v1/client/cart')->assertOk()->assertJsonPath('data.items.0.quantity', 2);
    }

    public function test_lines_that_become_unavailable_or_exceed_stock_are_kept_and_flagged(): void
    {
        $client = $this->client();
        $a = $this->variant(['price' => '10.00', 'stock' => 5]);
        $b = $this->variant(['price' => '20.00', 'stock' => 5]);
        $this->actingAsApi($client)->putJson("/api/v1/client/cart/items/{$a->id}", ['quantity' => 4])->assertOk();
        $this->actingAsApi($client)->putJson("/api/v1/client/cart/items/{$b->id}", ['quantity' => 1])->assertOk();

        $a->update(['stock' => 2]);
        $b->update(['is_active' => false]);

        $this->actingAsApi($client)->getJson('/api/v1/client/cart')
            ->assertOk()
            ->assertJsonPath('data.items.0.quantity', 4)
            ->assertJsonPath('data.items.0.exceeds_stock', true)
            ->assertJsonPath('data.items.1.available', false)
            ->assertJsonPath('data.count', 5)
            ->assertJsonPath('data.subtotal.amount', '40.00')
            ->assertJsonPath('data.has_issues', true);

        // The line can still be lowered to the stock or removed.
        $this->actingAsApi($client)->putJson("/api/v1/client/cart/items/{$a->id}", ['quantity' => 2])->assertOk()->assertJsonPath('data.items.0.exceeds_stock', false);
        $this->actingAsApi($client)->deleteJson("/api/v1/client/cart/items/{$b->id}")->assertOk()->assertJsonPath('data.has_issues', false);
    }

    public function test_live_price_changes_are_reflected(): void
    {
        $client = $this->client();
        $variant = $this->variant(['price' => '10.00']);
        $this->actingAsApi($client)->putJson("/api/v1/client/cart/items/{$variant->id}", ['quantity' => 2])->assertOk();
        $variant->update(['price' => '11.25']);

        $this->actingAsApi($client)->getJson('/api/v1/client/cart')->assertJsonPath('data.subtotal.amount', '22.50');
    }

    public function test_s9b_a_configured_maximum_per_line_is_a_validation_error(): void
    {
        app(AppSettings::class)->set('store.max_qty_per_line', 3);
        $client = $this->client();
        $variant = $this->variant(['stock' => 10]);

        $this->actingAsApi($client)->putJson("/api/v1/client/cart/items/{$variant->id}", ['quantity' => 4])
            ->assertStatus(422)->assertJsonPath('code', 'VALIDATION_FAILED')
            ->assertJsonPath('errors.quantity.0', __('store.max_quantity', ['max' => 3]));
        $this->actingAsApi($client)->putJson("/api/v1/client/cart/items/{$variant->id}", ['quantity' => 3])->assertOk();
    }
}
