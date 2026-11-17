<?php

namespace Tests\Feature\Store;

use App\Enums\MediaVisibility;
use App\Models\Media;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductCatalogTest extends TestCase
{
    use RefreshDatabase;

    private function product(array $attributes = [], ?ProductCategory $category = null, int $variants = 1): Product
    {
        $product = Product::factory()->create([...$attributes, ...($category ? ['category_id' => $category->id] : [])]);
        ProductVariant::factory()->count($variants)->create(['product_id' => $product->id]);

        return $product;
    }

    public function test_categories_are_public_active_and_ordered(): void
    {
        ProductCategory::factory()->create(['name' => ['en' => 'Second', 'ar' => 'ثاني'], 'sort' => 2]);
        ProductCategory::factory()->create(['name' => ['en' => 'First', 'ar' => 'أول'], 'sort' => 1]);
        ProductCategory::factory()->inactive()->create();

        $this->withHeader('Accept-Language', 'en')->getJson('/api/v1/shared/store/categories')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'First')
            ->assertJsonPath('data.1.name', 'Second')
            ->assertJsonStructure(['data' => [['id', 'name', 'image_url']]]);
    }

    public function test_list_is_public_paginated_and_hides_invisible_products(): void
    {
        $visible = $this->product(['base_price' => '85.50']);
        $visible->variants()->update(['price' => '70.00']); // S9b (D209): the card shows the variant price
        $this->product(['is_active' => false]);
        $this->product([], ProductCategory::factory()->inactive()->create());
        $noActiveVariant = Product::factory()->create();
        ProductVariant::factory()->inactive()->create(['product_id' => $noActiveVariant->id]);
        Product::factory()->create(); // no variants at all

        $this->getJson('/api/v1/shared/store/products')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $visible->id)
            ->assertJsonPath('data.0.price', ['amount' => '70.00', 'currency' => 'SAR'])
            ->assertJsonPath('data.0.price_from', false)
            ->assertJsonPath('data.0.in_stock', true)
            ->assertJsonPath('data.0.variants_count', 1)
            ->assertJsonPath('data.0.default_variant_id', $visible->variants()->first()->id)
            ->assertJsonMissingPath('data.0.stock')
            ->assertJsonStructure(['data' => [['id', 'category_id', 'name', 'image_url', 'price', 'in_stock', 'variants_count', 'default_variant_id']], 'links', 'meta' => ['current_page', 'per_page', 'total', 'last_page']]);
    }

    public function test_pagination_caps_per_page_at_fifty(): void
    {
        $category = ProductCategory::factory()->create();
        foreach (range(1, 3) as $_) {
            $this->product([], $category);
        }

        $this->getJson('/api/v1/shared/store/products?per_page=2')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 3)->assertJsonPath('meta.last_page', 2);
        $this->getJson('/api/v1/shared/store/products?per_page=500')->assertOk()->assertJsonPath('meta.per_page', 50);
    }

    public function test_filters_by_category_and_searches_both_languages(): void
    {
        $gifts = ProductCategory::factory()->create();
        $box = $this->product(['name' => ['en' => 'Gift box', 'ar' => 'صندوق هدايا']], $gifts);
        $this->product(['name' => ['en' => 'Candle', 'ar' => 'شمعة']]);

        $this->getJson("/api/v1/shared/store/products?filter[category_id]={$gifts->id}")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $box->id);
        $this->getJson('/api/v1/shared/store/products?q=GIFT')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $box->id);
        $this->getJson('/api/v1/shared/store/products?'.http_build_query(['q' => 'صندوق']))->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/shared/store/products?q=%25')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/shared/store/products?filter[category_id]=abc')->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_FAILED');
    }

    public function test_out_of_stock_products_stay_listed_and_several_variants_have_no_default(): void
    {
        $product = Product::factory()->create();
        ProductVariant::factory()->stock(0)->count(2)->create(['product_id' => $product->id]);

        $this->getJson('/api/v1/shared/store/products')
            ->assertOk()
            ->assertJsonPath('data.0.in_stock', false)
            ->assertJsonPath('data.0.variants_count', 2)
            ->assertJsonPath('data.0.default_variant_id', null);
    }

    public function test_details_list_active_variants_with_localized_attributes(): void
    {
        $product = Product::factory()->create(['description' => ['en' => 'Lovely', 'ar' => 'جميل']]);
        $small = ProductVariant::factory()->create([
            'product_id' => $product->id, 'price' => '60.00', 'stock' => 0,
            'attributes' => ['color' => ['en' => 'Red', 'ar' => 'أحمر'], 'size' => ['en' => 'Small', 'ar' => 'صغير']],
        ]);
        ProductVariant::factory()->inactive()->create(['product_id' => $product->id]);
        $product->media()->save(Media::query()->create(['collection' => 'image', 'disk' => MediaVisibility::Public->disk(), 'path' => 'demo/x.jpg', 'original_name' => 'x.jpg', 'mime' => 'image/jpeg', 'size' => 1, 'visibility' => MediaVisibility::Public]));

        $this->withHeader('Accept-Language', 'en')->getJson("/api/v1/shared/store/products/{$product->id}")
            ->assertOk()
            ->assertJsonPath('data.description', 'Lovely')
            ->assertJsonPath('data.category.id', $product->category_id)
            ->assertJsonCount(1, 'data.variants')
            ->assertJsonPath('data.variants.0.id', $small->id)
            ->assertJsonPath('data.variants.0.price.amount', '60.00')
            ->assertJsonPath('data.variants.0.in_stock', false)
            ->assertJsonPath('data.variants.0.attributes', [
                ['key' => 'size', 'label' => 'Size', 'value' => 'Small'],
                ['key' => 'color', 'label' => 'Colour', 'value' => 'Red'],
            ])
            ->assertJsonPath('data.default_variant_id', $small->id)
            ->assertJsonMissingPath('data.variants.0.stock');
        $this->assertNotNull($this->getJson("/api/v1/shared/store/products/{$product->id}")->json('data.image_url'));

        $this->withHeader('Accept-Language', 'ar')->getJson("/api/v1/shared/store/products/{$product->id}")
            ->assertJsonPath('data.variants.0.attributes.0.label', 'المقاس')
            ->assertJsonPath('data.variants.0.attributes.0.value', 'صغير');
    }

    public function test_hidden_or_unknown_products_are_404(): void
    {
        $hidden = $this->product(['is_active' => false]);

        $this->getJson("/api/v1/shared/store/products/{$hidden->id}")->assertNotFound()->assertJsonPath('code', 'NOT_FOUND');
        $this->getJson('/api/v1/shared/store/products/999999')->assertNotFound();
        $this->getJson('/api/v1/shared/store/products/abc')->assertNotFound();
    }

    public function test_signed_in_users_of_any_type_can_browse(): void
    {
        $this->product();

        foreach (['client', 'provider'] as $type) {
            $this->actingAsApi(User::factory()->{$type}()->create())->getJson('/api/v1/shared/store/products')->assertOk()->assertJsonCount(1, 'data');
        }
    }

    public function test_s9b_sort_in_stock_first_then_admin_sort_then_newest(): void
    {
        $soldOut = Product::factory()->create(['sort' => 0]);
        ProductVariant::factory()->stock(0)->create(['product_id' => $soldOut->id]);
        $second = $this->product(['sort' => 2]);
        $firstOld = $this->product(['sort' => 1, 'created_at' => now()->subDay()]);
        $firstNew = $this->product(['sort' => 1, 'created_at' => now()]);

        $ids = $this->getJson('/api/v1/shared/store/products')->assertOk()->json('data.*.id');
        $this->assertSame([$firstNew->id, $firstOld->id, $second->id, $soldOut->id], $ids);
    }

    public function test_s9b_card_price_is_from_the_lowest_active_variant(): void
    {
        $product = Product::factory()->create(['base_price' => '1.00']);
        ProductVariant::factory()->create(['product_id' => $product->id, 'price' => '30.00']);
        ProductVariant::factory()->create(['product_id' => $product->id, 'price' => '25.50']);
        ProductVariant::factory()->inactive()->create(['product_id' => $product->id, 'price' => '5.00']);

        $this->getJson('/api/v1/shared/store/products')->assertOk()
            ->assertJsonPath('data.0.price.amount', '25.50')
            ->assertJsonPath('data.0.price_from', true);
        $this->getJson("/api/v1/shared/store/products/{$product->id}")->assertOk()
            ->assertJsonPath('data.price.amount', '25.50')
            ->assertJsonPath('data.price_from', true);
    }
}
