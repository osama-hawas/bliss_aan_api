<?php

namespace Tests\Concerns;

use App\Enums\MediaVisibility;
use App\Enums\ProviderAccountType;
use App\Enums\ProviderStatus;
use App\Models\City;
use App\Models\Media;
use App\Models\Provider;
use App\Models\ProviderService;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\User;

/**
 * Minimal catalog and provider rows for discovery tests.
 */
trait BuildsCatalog
{
    protected function city(string $en = 'Riyadh', string $ar = 'الرياض', bool $active = true): City
    {
        return City::query()->create(['name' => ['en' => $en, 'ar' => $ar], 'is_active' => $active]);
    }

    protected function category(string $en = 'Photography', string $ar = 'التصوير', array $attributes = []): ServiceCategory
    {
        return ServiceCategory::query()->create(['name' => ['en' => $en, 'ar' => $ar], ...$attributes]);
    }

    protected function service(ServiceCategory $category, string $en = 'Event photography', ?string $guidePrice = '1000.00', array $attributes = []): Service
    {
        return Service::query()->create([
            'category_id' => $category->id,
            'name' => ['en' => $en, 'ar' => "ar {$en}"],
            'guide_price' => $guidePrice,
            ...$attributes,
        ]);
    }

    /**
     * An active, visible provider covering the given cities.
     *
     * @param  list<City>  $cities
     */
    protected function provider(string $name = 'Noura Lens', array $cities = [], array $attributes = []): Provider
    {
        $provider = Provider::query()->create([
            'user_id' => User::factory()->provider()->create()->id,
            'account_type' => ProviderAccountType::Individual,
            'display_name' => $name,
            'status' => ProviderStatus::Active,
            'activated_at' => now(),
            ...$attributes,
        ]);

        foreach ($cities as $city) {
            $provider->coverageAreas()->create(['city_id' => $city->id]);
        }

        return $provider;
    }

    protected function offer(Provider $provider, Service $service, ?string $customPrice = null, array $attributes = []): ProviderService
    {
        return $provider->services()->create(['service_id' => $service->id, 'custom_price' => $customPrice, ...$attributes]);
    }

    protected function media(string $collection, MediaVisibility $visibility = MediaVisibility::Public): Media
    {
        return Media::query()->create([
            'collection' => $collection,
            'disk' => $visibility->disk(),
            'path' => "{$collection}/".uniqid().'.jpg',
            'mime' => 'image/jpeg',
            'size' => 100,
            'visibility' => $visibility,
        ]);
    }
}
