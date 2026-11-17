<?php

namespace Tests\Feature\Store\Admin;

use App\Enums\AdminRole;
use App\Enums\MediaVisibility;
use App\Enums\UploadContext;
use App\Models\AuditLog;
use App\Models\Media;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsApplications;
use Tests\TestCase;

/**
 * Slice 18 (S18) — store categories in the dashboard (slice-18-contract.md §5.1, D271/D272/D276/D277).
 */
class ProductCategoryAdminTest extends TestCase
{
    use BuildsApplications, RefreshDatabase;

    private const URL = '/api/v1/admin/product-categories';

    private function officer(): User
    {
        return $this->staff(AdminRole::StoreContentOfficer);
    }

    private function catalogImage(User $uploader): Media
    {
        return Media::query()->create([
            'collection' => UploadContext::CatalogImage->value, 'disk' => MediaVisibility::Public->disk(), 'path' => 'catalog_image/'.uniqid().'.jpg',
            'mime' => 'image/jpeg', 'size' => 100, 'visibility' => MediaVisibility::Public, 'uploaded_by' => $uploader->id,
        ]);
    }

    public function test_only_products_manage_reaches_the_endpoints(): void
    {
        $this->actingAsApi($this->staff(AdminRole::OperationsSupport))->getJson(self::URL)->assertForbidden();
        $this->actingAsApi(User::factory()->client()->create())->getJson(self::URL)->assertForbidden();
        $this->getJson(self::URL)->assertUnauthorized();
        $this->actingAsApi($this->officer())->getJson(self::URL)->assertOk();
    }

    public function test_create_lists_and_shows_with_counts(): void
    {
        $admin = $this->officer();
        ProductCategory::factory()->create(['sort' => 4]);
        $image = $this->catalogImage($admin);

        $id = $this->actingAsApi($admin)->postJson(self::URL, ['name' => ['ar' => ' هدايا ', 'en' => 'Gifts'], 'image_media_id' => $image->id])
            ->assertCreated()
            ->assertJsonPath('data.name', ['ar' => 'هدايا', 'en' => 'Gifts'])
            ->assertJsonPath('data.sort', 5)
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.image.id', $image->id)
            ->json('data.id');
        Product::factory()->count(2)->create(['category_id' => $id]);
        Product::factory()->inactive()->create(['category_id' => $id]);

        $this->actingAsApi($admin)->getJson(self::URL."/{$id}")
            ->assertOk()->assertJsonPath('data.products_count', 3)->assertJsonPath('data.active_products_count', 2);
        $this->actingAsApi($admin)->getJson(self::URL.'?q=gif')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $id);
        $this->actingAsApi($admin)->getJson(self::URL.'?filter[status]=inactive')->assertOk()->assertJsonCount(0, 'data');
        $this->assertSame('product_category.created', AuditLog::query()->latest('id')->value('action'));
    }

    public function test_validation_names_and_images(): void
    {
        $admin = $this->officer();
        ProductCategory::factory()->create(['name' => ['ar' => 'هدايا', 'en' => 'Gifts']]);

        $this->actingAsApi($admin)->postJson(self::URL, ['name' => ['ar' => 'x']])->assertUnprocessable()->assertJsonValidationErrors(['name.ar', 'name.en']);
        $this->actingAsApi($admin)->postJson(self::URL, ['name' => ['ar' => 'هدايا', 'en' => 'GIFTS']])
            ->assertUnprocessable()->assertJsonValidationErrors(['name.ar', 'name.en']);

        // Someone else's upload, another context, or an attached file is refused.
        $foreign = $this->catalogImage($this->officer());
        $this->actingAsApi($admin)->postJson(self::URL, ['name' => ['ar' => 'ورد', 'en' => 'Flowers'], 'image_media_id' => $foreign->id])
            ->assertUnprocessable()->assertJsonValidationErrors(['image_media_id']);
        $this->assertSame(1, ProductCategory::query()->count());
    }

    public function test_update_replaces_and_removes_the_image_without_deleting_it(): void
    {
        $admin = $this->officer();
        $category = ProductCategory::factory()->create();
        $first = $this->catalogImage($admin);
        $second = $this->catalogImage($admin);

        $this->actingAsApi($admin)->putJson(self::URL."/{$category->id}", ['image_media_id' => $first->id])->assertOk()->assertJsonPath('data.image.id', $first->id);
        $this->actingAsApi($admin)->putJson(self::URL."/{$category->id}", ['image_media_id' => $second->id])->assertOk()->assertJsonPath('data.image.id', $second->id);
        $this->assertNull($first->refresh()->attachable_id);
        $this->actingAsApi($admin)->putJson(self::URL."/{$category->id}", ['name' => ['ar' => 'جديد', 'en' => 'New']])
            ->assertOk()->assertJsonPath('data.image.id', $second->id)->assertJsonPath('data.name.en', 'New');
        $this->actingAsApi($admin)->putJson(self::URL."/{$category->id}", ['image_media_id' => null])->assertOk()->assertJsonPath('data.image', null);
        $this->assertDatabaseHas('media', ['id' => $second->id, 'attachable_id' => null]);
    }

    public function test_stop_hides_its_products_and_activate_brings_them_back(): void
    {
        $admin = $this->officer();
        $category = ProductCategory::factory()->create();
        $product = Product::factory()->create(['category_id' => $category->id]);
        ProductVariant::factory()->create(['product_id' => $product->id]);

        $this->actingAsApi($admin)->postJson(self::URL."/{$category->id}/stop", [])->assertUnprocessable()->assertJsonValidationErrors(['reason']);
        $this->actingAsApi($admin)->postJson(self::URL."/{$category->id}/stop", ['reason' => 'Season over'])->assertOk()->assertJsonPath('data.is_active', false);
        $this->getJson("/api/v1/shared/store/products/{$product->id}")->assertNotFound();
        $this->actingAsApi($admin)->postJson(self::URL."/{$category->id}/stop", ['reason' => 'again'])->assertStatus(409)->assertJsonPath('code', 'INVALID_STATE');
        $this->assertDatabaseHas('audit_logs', ['action' => 'product_category.stopped', 'reason' => 'Season over']);

        $this->actingAsApi($admin)->postJson(self::URL."/{$category->id}/activate", [])->assertOk()->assertJsonPath('data.is_active', true);
        $this->getJson("/api/v1/shared/store/products/{$product->id}")->assertOk();
        $this->assertTrue($product->refresh()->is_active);
    }

    public function test_move_swaps_with_the_neighbour_and_renumbers_ties(): void
    {
        $admin = $this->officer();
        [$a, $b, $c] = ProductCategory::factory()->count(3)->create(['sort' => 0]);

        $this->actingAsApi($admin)->postJson(self::URL."/{$b->id}/move", ['direction' => 'up'])->assertOk()->assertJsonPath('data.sort', 1);
        $this->assertSame([$b->id, $a->id, $c->id], ProductCategory::query()->orderBy('sort')->pluck('id')->all());
        $this->actingAsApi($admin)->postJson(self::URL."/{$b->id}/move", ['direction' => 'up'])->assertStatus(409);
        $this->actingAsApi($admin)->postJson(self::URL."/{$c->id}/move", ['direction' => 'down'])->assertStatus(409);
        $this->actingAsApi($admin)->postJson(self::URL."/{$c->id}/move", ['direction' => 'left'])->assertUnprocessable();
        $this->actingAsApi($admin)->postJson(self::URL.'/999999/move', ['direction' => 'up'])->assertNotFound();
    }
}
