<?php

namespace Tests\Feature\Discovery;

use App\Enums\Audience;
use App\Models\Banner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCatalog;
use Tests\TestCase;

class CatalogTest extends TestCase
{
    use BuildsCatalog, RefreshDatabase;

    public function test_categories_are_public_active_and_ordered(): void
    {
        $this->category('Venues', 'القاعات', ['sort' => 2]);
        $first = $this->category('Photography', 'التصوير', ['sort' => 1]);
        $this->category('Hidden', 'مخفي', ['is_active' => false]);
        $first->media()->save($this->media('image'));

        $this->withHeader('Accept-Language', 'en')->getJson('/api/v1/shared/categories')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'Photography')
            ->assertJsonPath('data.1.name', 'Venues')
            ->assertJsonPath('data.1.image_url', null)
            ->assertJsonStructure(['data' => [['id', 'name', 'description', 'image_url']]]);
    }

    public function test_services_hide_inactive_rows_and_never_show_the_guide_price(): void
    {
        $photo = $this->category();
        $venues = $this->category('Venues', 'القاعات');
        $hiddenCategory = $this->category('Hidden', 'مخفي', ['is_active' => false]);
        $visible = $this->service($photo);
        $this->service($photo, 'Retired', attributes: ['is_active' => false]);
        $this->service($venues, 'Hall');
        $this->service($hiddenCategory, 'Orphan');

        $this->getJson('/api/v1/shared/services')->assertOk()->assertJsonCount(2, 'data');

        $this->getJson("/api/v1/shared/services?filter[category_id]={$photo->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $visible->id)
            ->assertJsonMissingPath('data.0.guide_price');

        $this->getJson('/api/v1/shared/services?filter[category_id]=abc')
            ->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_FAILED');
    }

    public function test_home_returns_running_client_banners_for_the_city_and_featured_providers(): void
    {
        $riyadh = $this->city();
        $jeddah = $this->city('Jeddah', 'جدة');
        $service = $this->service($this->category());

        $banner = fn (array $attributes) => Banner::query()->create(['title' => ['en' => 'B', 'ar' => 'ب'], 'app' => Audience::Client, ...$attributes]);
        $everywhere = $banner([]);
        $forRiyadh = $banner(['city_id' => $riyadh->id]);
        $banner(['city_id' => $jeddah->id]);
        $banner(['app' => Audience::Provider]);
        $banner(['is_active' => false]);
        $banner(['ends_at' => now()->subDay()]);
        $banner(['starts_at' => now()->addDay()]);

        $featured = $this->provider('Featured', [$riyadh], ['is_featured' => true]);
        $this->offer($featured, $service);
        $this->offer($this->provider('Not featured', [$riyadh]), $service);
        $this->offer($this->provider('Other city', [$jeddah], ['is_featured' => true]), $service);

        $response = $this->getJson("/api/v1/shared/home?city_id={$riyadh->id}")->assertOk();

        $this->assertSame([$everywhere->id, $forRiyadh->id], array_column($response->json('data.banners'), 'id'));
        $this->assertSame([$featured->id], array_column($response->json('data.featured_providers'), 'id'));
        $response->assertJsonCount(1, 'data.categories');

        $this->getJson('/api/v1/shared/home')->assertOk()->assertJsonCount(2, 'data.featured_providers')->assertJsonCount(1, 'data.banners');
    }

    public function test_home_rejects_an_unknown_or_inactive_city(): void
    {
        $inactive = $this->city('Old', 'قديمة', active: false);

        $this->getJson('/api/v1/shared/home?city_id=999')->assertUnprocessable();
        $this->getJson("/api/v1/shared/home?city_id={$inactive->id}")->assertUnprocessable();
    }
}
