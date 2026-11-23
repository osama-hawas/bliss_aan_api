<?php

namespace Tests\Feature\Store\Admin;

use App\Enums\AdminRole;
use App\Models\AppSetting;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Settings\AppSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsApplications;
use Tests\TestCase;

/**
 * Slice 18 (S18) — products and variants in the dashboard (slice-18-contract.md §5.2–§5.3, D273/D275/D276/D278/D281).
 */
class ProductAdminTest extends TestCase
{
    use BuildsApplications, RefreshDatabase;

    private const URL = '/api/v1/admin/products';

    private const VARIANTS = '/api/v1/admin/product-variants';

    private function officer(): User
    {
        return $this->staff(AdminRole::StoreContentOfficer);
    }

    private function body(ProductCategory $category, array $overrides = []): array
    {
        return [
            'category_id' => $category->id,
            'name' => ['ar' => 'شمعة', 'en' => 'Candle'],
            'description' => ['ar' => 'وصف', 'en' => 'Desc'],
            'variants' => [
                ['sku' => 'can-s', 'attributes' => ['size' => ['ar' => 'صغير', 'en' => 'Small']], 'price' => '60', 'stock' => 8],
                ['sku' => 'CAN-L', 'attributes' => ['size' => ['ar' => 'كبير', 'en' => 'Large']], 'price' => '85.50', 'stock' => 0],
            ],
            ...$overrides,
        ];
    }

    public function test_permission(): void
    {
        $this->actingAsApi($this->staff(AdminRole::DeliveryOfficer))->getJson(self::URL)->assertForbidden();
        $this->actingAsApi($this->staff(AdminRole::AccountsOfficer))->postJson(self::VARIANTS.'/1/stock', [])->assertForbidden();
    }

    public function test_create_with_variants_records_initial_stock_and_base_price(): void
    {
        $admin = $this->officer();
        $category = ProductCategory::factory()->create();

        $response = $this->actingAsApi($admin)->postJson(self::URL, $this->body($category))
            ->assertCreated()
            ->assertJsonPath('data.name.en', 'Candle')
            ->assertJsonPath('data.base_price.amount', '60.00')
            ->assertJsonPath('data.stock_total', 8)
            ->assertJsonPath('data.visible', true)
            ->assertJsonPath('data.variants.0.sku', 'CAN-S')
            ->assertJsonPath('data.variants.0.stock', 8)
            ->assertJsonPath('data.variants.1.price.amount', '85.50')
            ->assertJsonPath('data.low_stock_threshold', null)
            ->assertJsonPath('data.history.0.action', 'product.created');

        $variantId = $response->json('data.variants.0.id');
        $this->assertDatabaseHas('stock_movements', ['product_variant_id' => $variantId, 'source' => 'initial', 'change' => 8, 'stock_after' => 8, 'actor_id' => $admin->id]);
        $this->assertSame(1, StockMovement::query()->count()); // zero initial stock writes no row
        $this->getJson('/api/v1/shared/store/products/'.$response->json('data.id'))->assertOk();
    }

    public function test_create_validation(): void
    {
        $admin = $this->officer();
        $category = ProductCategory::factory()->create();
        ProductVariant::factory()->create(['sku' => 'TAKEN-1']);

        $this->actingAsApi($admin)->postJson(self::URL, $this->body($category, ['variants' => []]))->assertUnprocessable()->assertJsonValidationErrors(['variants']);
        $this->actingAsApi($admin)->postJson(self::URL, $this->body($category, ['category_id' => 999999]))->assertUnprocessable()->assertJsonValidationErrors(['category_id']);
        $this->actingAsApi($admin)->postJson(self::URL, $this->body($category, ['variants' => [['sku' => 'taken-1', 'price' => 10, 'stock' => 1]]]))
            ->assertUnprocessable()->assertJsonValidationErrors(['variants.0.sku']);
        $this->actingAsApi($admin)->postJson(self::URL, $this->body($category, ['variants' => [['sku' => 'A-1', 'price' => 10, 'stock' => 1], ['sku' => 'a-1', 'price' => 10, 'stock' => 1]]]))
            ->assertUnprocessable();
        $this->actingAsApi($admin)->postJson(self::URL, $this->body($category, ['variants' => [['sku' => 'A-1', 'price' => 10, 'stock' => 1], ['sku' => 'A-2', 'price' => 12, 'stock' => 1]]]))
            ->assertUnprocessable()->assertJsonValidationErrors(['variants.1.attributes']);
        $this->actingAsApi($admin)->postJson(self::URL, $this->body($category, ['variants' => [['sku' => 'A 1', 'price' => 0, 'stock' => -1]]]))
            ->assertUnprocessable()->assertJsonValidationErrors(['variants.0.sku', 'variants.0.price', 'variants.0.stock']);
        $this->actingAsApi($admin)->postJson(self::URL, $this->body($category, ['variants' => [['sku' => 'A-1', 'price' => '1.005', 'stock' => 1]]]))
            ->assertUnprocessable()->assertJsonValidationErrors(['variants.0.price']);
        // The TAKEN-1 factory variant brings its own product: count only this category's.
        $this->assertSame(0, Product::query()->where('category_id', $category->id)->count());
    }

    public function test_list_filters_and_search_by_sku(): void
    {
        $admin = $this->officer();
        $flowers = ProductCategory::factory()->create();
        $rose = Product::factory()->create(['category_id' => $flowers->id, 'name' => ['ar' => 'ورد', 'en' => 'Rose']]);
        ProductVariant::factory()->create(['product_id' => $rose->id, 'sku' => 'ROSE-RED', 'stock' => 3, 'price' => '40.00']);
        ProductVariant::factory()->create(['product_id' => $rose->id, 'sku' => 'ROSE-WHT', 'stock' => 0, 'price' => '30.00']);
        $empty = Product::factory()->create(['name' => ['ar' => 'فارغ', 'en' => 'Empty']]);
        ProductVariant::factory()->create(['product_id' => $empty->id, 'stock' => 0]);
        Product::factory()->inactive()->create();

        $this->actingAsApi($admin)->getJson(self::URL)->assertOk()->assertJsonCount(3, 'data');
        $this->actingAsApi($admin)->getJson(self::URL.'?q=rose-wh')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $rose->id)
            ->assertJsonPath('data.0.price_from.amount', '30.00')
            ->assertJsonPath('data.0.stock_total', 3)
            ->assertJsonPath('data.0.low_stock', null);
        $this->actingAsApi($admin)->getJson(self::URL."?filter[category_id]={$flowers->id}")->assertOk()->assertJsonCount(1, 'data');
        $this->actingAsApi($admin)->getJson(self::URL.'?filter[status]=inactive')->assertOk()->assertJsonCount(1, 'data');
        $this->actingAsApi($admin)->getJson(self::URL.'?filter[stock]=out_of_stock&filter[status]=active')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $empty->id);
        $this->actingAsApi($admin)->getJson(self::URL.'?filter[stock]=in_stock')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $rose->id);
        $this->actingAsApi($admin)->getJson(self::URL.'?sort=name')->assertUnprocessable();
    }

    public function test_low_stock_needs_the_setting(): void
    {
        $admin = $this->officer();
        $product = Product::factory()->create();
        ProductVariant::factory()->create(['product_id' => $product->id, 'stock' => 2]);

        $this->actingAsApi($admin)->getJson(self::URL.'?filter[low_stock]=1')->assertStatus(409)->assertJsonPath('code', 'NOT_CONFIGURED');

        AppSetting::query()->updateOrCreate(['key' => 'store.low_stock_threshold'], ['value' => 2]);
        app(AppSettings::class)->flush();
        $this->actingAsApi($admin)->getJson(self::URL.'?filter[low_stock]=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.low_stock', true);
        $this->actingAsApi($admin)->getJson(self::URL."/{$product->id}")->assertOk()
            ->assertJsonPath('data.low_stock_threshold', 2)->assertJsonPath('data.variants.0.low_stock', true);
    }

    public function test_update_product_fields_and_history(): void
    {
        $admin = $this->officer();
        $product = Product::factory()->create();
        ProductVariant::factory()->create(['product_id' => $product->id]);
        $other = ProductCategory::factory()->create();

        $this->actingAsApi($admin)->putJson(self::URL."/{$product->id}", ['category_id' => $other->id, 'name' => ['ar' => 'جديد', 'en' => 'New'], 'description' => null])
            ->assertOk()
            ->assertJsonPath('data.category.id', $other->id)
            ->assertJsonPath('data.description', null)
            ->assertJsonPath('data.history.0.action', 'product.updated')
            ->assertJsonPath('data.history.0.new.name.en', 'New');
        $this->actingAsApi($admin)->putJson(self::URL."/{$product->id}", ['stock' => 5])->assertOk(); // unknown fields ignored, stock never edited here
        $this->assertSame(10, $product->variants()->value('stock'));
        $this->actingAsApi($admin)->putJson(self::URL.'/999999', ['name' => ['ar' => 'xx', 'en' => 'xx']])->assertNotFound();
    }

    public function test_stop_and_activate_a_product_keeps_cart_lines_flagged(): void
    {
        $admin = $this->officer();
        $client = User::factory()->client()->create();
        $variant = ProductVariant::factory()->create(['stock' => 5]);
        $this->actingAsApi($client)->putJson("/api/v1/client/cart/items/{$variant->id}", ['quantity' => 1])->assertOk();

        $this->actingAsApi($admin)->postJson(self::URL."/{$variant->product_id}/stop", ['reason' => 'Supplier issue', 'internal_note' => 'call them'])
            ->assertOk()->assertJsonPath('data.is_active', false)->assertJsonPath('data.visible', false);
        $this->actingAsApi($client)->getJson('/api/v1/client/cart')->assertOk()->assertJsonPath('data.items.0.available', false);
        $this->actingAsApi($admin)->postJson(self::URL."/{$variant->product_id}/stop", ['reason' => 'again'])->assertConflict();
        $this->actingAsApi($admin)->postJson(self::URL."/{$variant->product_id}/activate", [])->assertOk()->assertJsonPath('data.is_active', true);
        $this->assertDatabaseHas('cart_items', ['product_variant_id' => $variant->id]);
    }

    public function test_variants_add_edit_stop_and_base_price(): void
    {
        $admin = $this->officer();
        $product = Product::factory()->create(['base_price' => '50.00']);
        $small = ProductVariant::factory()->create(['product_id' => $product->id, 'price' => '50.00', 'attributes' => ['size' => ['ar' => 'صغير', 'en' => 'Small']]]);

        $this->actingAsApi($admin)->postJson(self::URL."/{$product->id}/variants", ['sku' => 'big-1', 'attributes' => ['size' => ['ar' => 'كبير', 'en' => 'Large']], 'price' => '40', 'stock' => 3])
            ->assertCreated()->assertJsonPath('data.base_price.amount', '40.00')->assertJsonCount(2, 'data.variants');
        $this->assertDatabaseHas('stock_movements', ['source' => 'initial', 'change' => 3]);
        $this->actingAsApi($admin)->postJson(self::URL."/{$product->id}/variants", ['sku' => 'dup-1', 'attributes' => ['size' => ['ar' => 'صغير', 'en' => 'SMALL']], 'price' => '40', 'stock' => 3])
            ->assertUnprocessable()->assertJsonValidationErrors(['attributes']);

        $this->actingAsApi($admin)->putJson(self::VARIANTS."/{$small->id}", ['price' => '30.00', 'sku' => 'small-x', 'stock' => 99])
            ->assertOk()->assertJsonPath('data.base_price.amount', '30.00')->assertJsonPath('data.variants.0.sku', 'SMALL-X')->assertJsonPath('data.variants.0.stock', 10);
        $this->assertDatabaseHas('audit_logs', ['action' => 'product_variant.updated']);

        $this->actingAsApi($admin)->postJson(self::VARIANTS."/{$small->id}/stop", ['reason' => 'Discontinued'])
            ->assertOk()->assertJsonPath('data.variants.0.is_active', false)->assertJsonPath('data.base_price.amount', '40.00');
        $this->actingAsApi($admin)->putJson(self::VARIANTS."/{$small->id}", ['attributes' => ['size' => ['ar' => 'كبير', 'en' => 'Large']]])->assertOk(); // stopped: no clash check
        $this->actingAsApi($admin)->postJson(self::VARIANTS."/{$small->id}/activate", [])->assertUnprocessable()->assertJsonValidationErrors(['attributes']);
        $this->actingAsApi($admin)->putJson(self::VARIANTS.'/999999', ['price' => 1])->assertNotFound();
        $this->assertSame(2, ProductVariant::query()->count()); // nothing deleted
    }

    public function test_numeric_skus_name_the_right_field(): void
    {
        $admin = $this->officer();
        $category = ProductCategory::factory()->create();
        ProductVariant::factory()->create(['sku' => '777']);

        // L3: "12345" twice in one body, and a numeric SKU already taken.
        $this->actingAsApi($admin)->postJson(self::URL, $this->body($category, ['variants' => [
            ['sku' => '12345', 'attributes' => ['size' => ['ar' => 'أ', 'en' => 'A']], 'price' => 10, 'stock' => 1],
            ['sku' => '12345', 'attributes' => ['size' => ['ar' => 'ب', 'en' => 'B']], 'price' => 10, 'stock' => 1],
        ]]))->assertUnprocessable()->assertJsonValidationErrors(['variants.0.sku']);
        $this->actingAsApi($admin)->postJson(self::URL, $this->body($category, ['variants' => [['sku' => '777', 'price' => 10, 'stock' => 1]]]))
            ->assertUnprocessable()->assertJsonValidationErrors(['variants.0.sku']);
    }

    public function test_a_sku_taken_by_a_concurrent_save_is_a_422_not_a_500(): void
    {
        $admin = $this->officer();
        $category = ProductCategory::factory()->create();
        $other = Product::factory()->create();

        // L2: the pre-check passed, then another save takes the SKU just before this insert.
        ProductVariant::creating(function (ProductVariant $variant) use ($other) {
            if ($variant->sku === 'RACE-1' && ! ProductVariant::query()->where('sku', 'RACE-1')->exists()) {
                ProductVariant::query()->insert(['product_id' => $other->id, 'sku' => 'RACE-1', 'price' => '1.00', 'stock' => 0, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
            }
        });

        $this->actingAsApi($admin)->postJson(self::URL, $this->body($category, ['variants' => [['sku' => 'race-1', 'price' => 10, 'stock' => 1]]]))
            ->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_FAILED')->assertJsonValidationErrors(['variants.0.sku']);
        $this->assertSame(0, Product::query()->where('category_id', $category->id)->count());
    }

    public function test_variant_errors_are_plain_messages_not_raw_keys(): void
    {
        $admin = $this->officer();
        $category = ProductCategory::factory()->create();
        $bad = ['variants' => [['sku' => 'A 1', 'price' => 'abc', 'stock' => 1.5]]];

        $errors = $this->withHeader('Accept-Language', 'ar')->actingAsApi($admin)->postJson(self::URL, $this->body($category, $bad))
            ->assertUnprocessable()->json('errors');
        $this->assertSame('أدخلي سعر الخيار أرقامًا فقط، مثل 150 أو 99.50.', $errors['variants.0.price'][0]);
        $this->assertSame('كمية المخزون رقم صحيح من 0 فأكثر، بدون كسور.', $errors['variants.0.stock'][0]);
        $this->assertSame('رمز SKU يقبل حروفًا إنجليزية وأرقامًا و - و _ فقط، بدون مسافات. مثال: DRESS-RED-M.', $errors['variants.0.sku'][0]);

        $errors = $this->withHeader('Accept-Language', 'en')->actingAsApi($admin)->postJson(self::URL, $this->body($category, ['variants' => [['sku' => 'A-1', 'stock' => 1]]]))
            ->assertUnprocessable()->json('errors');
        $this->assertSame('Enter the variant price.', $errors['variants.0.price'][0]);
    }
}
