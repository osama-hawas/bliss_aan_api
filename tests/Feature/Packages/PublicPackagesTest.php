<?php

namespace Tests\Feature\Packages;

use App\Enums\ProviderStatus;
use App\Models\EventType;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsPackages;
use Tests\Concerns\BuildsServiceRequests;
use Tests\TestCase;

/**
 * Slice 24 §5: `shared/packages`, `shared/packages/{id}` and the Home `packages` block. Only active packages with a
 * visible service (D352), only their visible services, no package price (D350) and a market "starting from" per service
 * (D355).
 */
class PublicPackagesTest extends TestCase
{
    use BuildsPackages, BuildsServiceRequests, RefreshDatabase;

    private Service $makeup;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMarket();
        $this->makeup = $this->service($this->category('Beauty', 'التجميل'), 'Bridal makeup', '800.00');
    }

    public function test_guests_see_visible_packages_in_admin_order(): void
    {
        $second = $this->package([$this->makeup], 'Beauty day');
        $first = $this->package([$this->photo, $this->makeup], 'Bride day', [$this->wedding], ['sort' => 0]);
        $this->package([$this->photo], 'Stopped', [], ['is_active' => false]);
        $hidden = $this->service($this->category('Hidden'), 'Retired', null, ['is_active' => false]);
        $this->package([$hidden], 'No visible service');

        $response = $this->withHeader('Accept-Language', 'en')->getJson('/api/v1/shared/packages')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $first->id)
            ->assertJsonPath('data.0.name', 'Bride day')
            ->assertJsonPath('data.0.services_count', 2)
            ->assertJsonPath('data.0.services.0.id', $this->photo->id)
            ->assertJsonPath('data.0.event_types.0.name', 'Wedding')
            ->assertJsonPath('data.1.id', $second->id);

        $this->assertArrayNotHasKey('price', $response->json('data.0'));
    }

    public function test_hidden_services_are_left_out_of_a_visible_package(): void
    {
        $this->makeup->category->update(['is_active' => false]);
        $package = $this->package([$this->photo, $this->makeup]);

        $this->getJson('/api/v1/shared/packages')->assertOk()->assertJsonPath('data.0.services_count', 1);
        $this->getJson("/api/v1/shared/packages/{$package->id}")->assertOk()->assertJsonCount(1, 'data.services')->assertJsonPath('data.services.0.id', $this->photo->id);
    }

    public function test_event_type_filter_includes_packages_for_every_occasion(): void
    {
        $engagement = EventType::query()->create(['name' => ['en' => 'Engagement', 'ar' => 'خطوبة'], 'is_active' => true, 'sort' => 2]);
        $wedding = $this->package([$this->photo], 'Wedding pack', [$this->wedding]);
        $any = $this->package([$this->makeup], 'Any occasion');
        $this->package([$this->makeup], 'Engagement pack', [$engagement]);

        $ids = $this->getJson("/api/v1/shared/packages?filter[event_type_id]={$this->wedding->id}")->assertOk()->json('data.*.id');
        $this->assertEqualsCanonicalizing([$wedding->id, $any->id], $ids);
        $this->getJson('/api/v1/shared/packages?filter[event_type_id]=abc')->assertUnprocessable();
    }

    public function test_detail_has_request_form_data_and_starting_prices(): void
    {
        $addon = $this->catalogAddon('Album', $this->photo);
        $this->matchingProvider('Noura Lens', ['custom_price' => '1200.00']);
        $this->matchingProvider('Huda Studio'); // guide price 1000.00
        $this->provider('Hidden Studio', [$this->riyadh], ['status' => ProviderStatus::Suspended]);
        $package = $this->package([$this->photo, $this->makeup], 'Bride day', [$this->wedding]);

        $response = $this->withHeader('Accept-Language', 'en')->getJson("/api/v1/shared/packages/{$package->id}")
            ->assertOk()
            ->assertJsonPath('data.name', 'Bride day')
            ->assertJsonPath('data.description', 'Bride day description')
            ->assertJsonPath('data.event_types.0.id', $this->wedding->id)
            ->assertJsonPath('data.services.0.id', $this->photo->id)
            ->assertJsonPath('data.services.0.category.name', 'Photography')
            ->assertJsonPath('data.services.0.guest_count_mode', 'optional')
            ->assertJsonPath('data.services.0.addons.0.id', $addon->id)
            ->assertJsonPath('data.services.0.starting_price.amount', '1000.00')
            ->assertJsonPath('data.services.0.providers_count', 2)
            // Nobody offers makeup yet: no price, never the bare guide price (D34).
            ->assertJsonPath('data.services.1.starting_price', null)
            ->assertJsonPath('data.services.1.providers_count', 0);

        $this->assertArrayNotHasKey('price', $response->json('data'));
        $this->assertArrayNotHasKey('guide_price', $response->json('data.services.1'));
    }

    public function test_detail_is_a_404_when_not_visible(): void
    {
        $stopped = $this->package([$this->photo], 'Stopped', [], ['is_active' => false]);
        $this->getJson("/api/v1/shared/packages/{$stopped->id}")->assertNotFound();
        $this->getJson('/api/v1/shared/packages/999999')->assertNotFound();

        $package = $this->package([$this->photo]);
        $this->photo->update(['is_active' => false]);
        $this->getJson("/api/v1/shared/packages/{$package->id}")->assertNotFound();
    }

    public function test_home_has_a_packages_block(): void
    {
        $this->getJson('/api/v1/shared/home')->assertOk()->assertJsonPath('data.packages', []);

        $package = $this->package([$this->photo]);
        $this->package([$this->photo], 'Stopped', [], ['is_active' => false]);

        $this->getJson('/api/v1/shared/home')->assertOk()->assertJsonCount(1, 'data.packages')->assertJsonPath('data.packages.0.id', $package->id);
        $this->actingAsApi($this->client)->getJson('/api/v1/shared/home')->assertOk()->assertJsonCount(1, 'data.packages');
    }
}
