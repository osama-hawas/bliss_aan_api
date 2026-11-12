<?php

namespace Tests\Feature\ServiceRequests;

use App\Models\EventType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsServiceRequests;
use Tests\TestCase;

/**
 * Read-only data the request form needs (Slice 4 app integration): event types, the catalog add-ons of a service
 * (open requests) and the catalog `addon_id` of a provider's add-on (direct requests).
 */
class RequestFormDataTest extends TestCase
{
    use BuildsServiceRequests, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMarket();
    }

    public function test_event_types_are_public_active_and_sorted(): void
    {
        EventType::query()->create(['name' => ['en' => 'Engagement', 'ar' => 'خطوبة'], 'is_active' => true, 'sort' => 0]);
        EventType::query()->create(['name' => ['en' => 'Retired', 'ar' => 'قديم'], 'is_active' => false, 'sort' => 2]);

        $this->withHeader('Accept-Language', 'en')->getJson('/api/v1/shared/event-types')
            ->assertOk()
            ->assertJsonPath('data', [
                ['id' => EventType::query()->where('sort', 0)->value('id'), 'name' => 'Engagement'],
                ['id' => $this->wedding->id, 'name' => 'Wedding'],
            ]);
        $this->withHeader('Accept-Language', 'ar')->getJson('/api/v1/shared/event-types')->assertJsonPath('data.1.name', 'زفاف');
    }

    public function test_catalog_services_list_their_active_addons(): void
    {
        $album = $this->catalogAddon('Album');
        $this->catalogAddon('Retired')->update(['is_active' => false]);

        $this->withHeader('Accept-Language', 'en')->getJson('/api/v1/shared/services')
            ->assertOk()
            ->assertJsonPath('data.0.addons', [['id' => $album->id, 'name' => 'Album', 'description' => null]]);
    }

    public function test_provider_service_addons_carry_the_catalog_addon_id(): void
    {
        $provider = $this->matchingProvider();
        $addon = $this->catalogAddon();
        $priced = $this->priceAddon($provider, $addon);
        $offered = $provider->services()->sole();

        $this->getJson("/api/v1/shared/providers/{$provider->id}/services/{$offered->id}")
            ->assertOk()
            ->assertJsonPath('data.addons.0.id', $priced->id)
            ->assertJsonPath('data.addons.0.addon_id', $addon->id);
    }
}
