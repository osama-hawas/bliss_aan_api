<?php

namespace Tests\Feature\Admin\Catalog;

use App\Enums\AdminRole;
use App\Models\Media;
use App\Models\ServiceCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsAdminCatalog;
use Tests\Concerns\BuildsCatalog;
use Tests\TestCase;

/**
 * Slice 16 §4.1: categories are created, edited, stopped / activated with a reason and reordered, never deleted (D180).
 */
class CatalogCategoriesTest extends TestCase
{
    use BuildsAdminCatalog, BuildsCatalog, RefreshDatabase;

    public function test_create_puts_an_active_category_last_with_its_image_and_audits_it(): void
    {
        $this->category('Venues', 'القاعات', ['sort' => 4]);
        $image = $this->catalogImage();

        $response = $this->catalogPost('/categories', [
            'name' => $this->both('Flowers', 'الورود'),
            'description' => $this->both('Fresh arrangements'),
            'image_media_id' => $image->id,
        ])->assertCreated()
            ->assertJsonPath('message', 'Category saved.')
            ->assertJsonPath('data.name', ['ar' => 'الورود', 'en' => 'Flowers'])
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.status_label', 'Active')
            ->assertJsonPath('data.sort', 5)
            ->assertJsonPath('data.image.id', $image->id)
            ->assertJsonPath('data.services_count', 0);

        $category = ServiceCategory::query()->findOrFail($response->json('data.id'));
        $this->assertSame($image->id, $category->image?->id);
        $this->assertSame('image', $image->refresh()->collection);
        $this->assertCount(1, $this->audits('catalog_category_created'));
    }

    public function test_create_validates_both_languages_and_the_image_owner(): void
    {
        $this->catalogPost('/categories', ['name' => ['ar' => 'ورود']])->assertUnprocessable()->assertJsonValidationErrors(['name.en']);
        $this->catalogPost('/categories', [])->assertUnprocessable()->assertJsonValidationErrors(['name']);
        $this->catalogPost('/categories', ['name' => $this->both('A')])->assertUnprocessable()->assertJsonValidationErrors(['name.en']);

        $someoneElses = $this->catalogImage($this->staffWith(AdminRole::SuperAdmin));
        $this->catalogPost('/categories', ['name' => $this->both('Flowers'), 'image_media_id' => $someoneElses->id])
            ->assertUnprocessable()->assertJsonValidationErrors(['image_media_id']);
        $this->assertSame(0, ServiceCategory::query()->count());
    }

    public function test_update_changes_only_sent_fields_and_null_clears_the_description(): void
    {
        $category = $this->category('Venues', 'القاعات', ['description' => ['en' => 'Halls', 'ar' => 'قاعات']]);
        $old = $this->catalogImage();
        $this->catalogPut("/categories/{$category->id}", ['image_media_id' => $old->id])->assertOk();

        $this->catalogPut("/categories/{$category->id}", [])->assertUnprocessable();
        $this->catalogPut("/categories/{$category->id}", ['description' => null, 'image_media_id' => null])
            ->assertOk()
            ->assertJsonPath('data.description', null)
            ->assertJsonPath('data.image', null)
            ->assertJsonPath('data.name.en', 'Venues');

        $this->assertNull(DB::table('service_categories')->where('id', $category->id)->value('description'));
        $this->assertNull($old->refresh()->attachable_id); // detached, not deleted
        $this->assertModelExists($old);
        $audit = $this->audits('catalog_category_updated')->last();
        $this->assertEquals(['description' => null, 'image_media_id' => null], $audit->new);
    }

    public function test_list_searches_both_languages_filters_and_sorts(): void
    {
        $this->category('Venues', 'القاعات', ['sort' => 2]);
        $this->category('Makeup', 'المكياج', ['sort' => 1, 'is_active' => false]);

        $this->catalogGet('/categories')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.name.en', 'Makeup');
        $this->catalogGet('/categories?q=القاع')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name.en', 'Venues');
        $this->catalogGet('/categories?q=makeup')->assertOk()->assertJsonCount(1, 'data');
        $this->catalogGet('/categories?filter[status]=inactive')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.status', 'inactive');
        $this->catalogGet('/categories?sort=-sort')->assertOk()->assertJsonPath('data.0.name.en', 'Venues');
        $this->catalogGet('/categories?sort=name')->assertOk()->assertJsonPath('data.0.name.en', 'Makeup');
        $this->catalogGet('/categories?sort=id')->assertUnprocessable();
        $this->catalogGet('/categories?filter[status]=deleted')->assertUnprocessable();
    }

    public function test_stop_needs_a_reason_hides_its_services_and_activation_restores_them(): void
    {
        $category = $this->category();
        $service = $this->service($category);
        $provider = $this->provider();
        $offered = $this->offer($provider, $service, '900.00');

        $this->catalogPost("/categories/{$category->id}/stop", [])->assertUnprocessable()->assertJsonValidationErrors(['reason']);
        $this->catalogPost("/categories/{$category->id}/stop", ['reason' => 'Seasonal pause', 'internal_note' => 'until Q2'])
            ->assertOk()->assertJsonPath('data.status', 'inactive');
        $this->catalogPost("/categories/{$category->id}/stop", ['reason' => 'Again'])->assertConflict()->assertJsonPath('code', 'INVALID_STATE');

        $this->getJson('/api/v1/shared/categories')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/shared/providers/{$provider->id}/services/{$offered->id}")->assertNotFound();
        $this->assertTrue($service->refresh()->is_active);
        $this->assertSame('900.00', (string) $offered->refresh()->custom_price);
        $this->assertTrue($offered->is_active);

        $this->catalogPost("/categories/{$category->id}/activate")->assertOk()->assertJsonPath('data.status', 'active');
        $this->catalogPost("/categories/{$category->id}/activate")->assertConflict();
        $this->getJson("/api/v1/shared/providers/{$provider->id}/services/{$offered->id}")->assertOk();

        $stop = $this->audits('catalog_category_stopped')->sole();
        $this->assertSame('Seasonal pause', $stop->reason);
        $this->assertSame('until Q2', $stop->new['internal_note']);
        $this->assertCount(1, $this->audits('catalog_category_activated'));
    }

    public function test_impact_counts_its_active_services(): void
    {
        $category = $this->category();
        $a = $this->service($category, 'A');
        $b = $this->service($category, 'B');
        $this->service($category, 'Stopped', attributes: ['is_active' => false]);
        $noura = $this->provider('Noura');
        $huda = $this->provider('Huda');
        $this->offer($noura, $a);
        $this->offer($noura, $b, '700.00');
        $this->offer($huda, $a);
        $this->offer($huda, $b, attributes: ['is_active' => false]);

        $this->catalogPost("/categories/{$category->id}/impact")
            ->assertOk()
            ->assertJsonPath('data.services_count', 2)
            ->assertJsonPath('data.providers_total', 2)
            ->assertJsonPath('data.providers_default', 2)
            ->assertJsonPath('data.providers_custom', 1)
            ->assertJsonPath('data.open_requests', 0)
            ->assertJsonPath('data.active_bookings', 0);
    }

    public function test_move_swaps_neighbours_renumbers_ties_and_refuses_the_edge(): void
    {
        $first = $this->category('First', attributes: ['sort' => 0]);
        $second = $this->category('Second', attributes: ['sort' => 0]);
        $third = $this->category('Third', attributes: ['sort' => 0]);

        $this->catalogPost("/categories/{$third->id}/move", ['direction' => 'up'])->assertOk()->assertJsonPath('data.sort', 2);
        $this->assertSame([1, 3, 2], [$first->refresh()->sort, $second->refresh()->sort, $third->refresh()->sort]);

        $this->catalogPost("/categories/{$first->id}/move", ['direction' => 'up'])->assertConflict();
        $this->catalogPost("/categories/{$first->id}/move", ['direction' => 'left'])->assertUnprocessable();
        $this->assertCount(1, $this->audits('catalog_category_moved'));
        $this->assertSame(0, Media::query()->count());
    }
}
