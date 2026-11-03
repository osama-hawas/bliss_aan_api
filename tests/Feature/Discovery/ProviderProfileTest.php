<?php

namespace Tests\Feature\Discovery;

use App\Enums\MediaVisibility;
use App\Enums\ProviderStatus;
use App\Models\Addon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCatalog;
use Tests\TestCase;

class ProviderProfileTest extends TestCase
{
    use BuildsCatalog, RefreshDatabase;

    public function test_profile_shows_public_data_offered_services_and_published_portfolio(): void
    {
        $riyadh = $this->city();
        $category = $this->category();
        $provider = $this->provider('Noura', [$riyadh], ['bio' => 'Hello', 'started_year' => 2019, 'legal_name' => 'Secret Co']);
        $offered = $this->offer($provider, $this->service($category), '800.00');
        $this->offer($provider, $this->service($category, 'Paused'), attributes: ['is_active' => false]);
        $this->offer($provider, $this->service($category, 'Retired', attributes: ['is_active' => false]));
        $provider->media()->save($this->media('avatar'));

        $shown = $provider->portfolioItems()->create(['media_id' => $this->media('portfolio')->id, 'caption' => 'Wedding']);
        $provider->portfolioItems()->create(['media_id' => $this->media('portfolio')->id, 'hidden_by_admin' => true]);
        $provider->portfolioItems()->create(['media_id' => $this->media('booking_update', MediaVisibility::Private)->id]);

        $this->withHeader('Accept-Language', 'en')->getJson("/api/v1/shared/providers/{$provider->id}")
            ->assertOk()
            ->assertJsonPath('data.bio', 'Hello')
            ->assertJsonPath('data.started_year', 2019)
            ->assertJsonPath('data.coverage_cities', [['id' => $riyadh->id, 'name' => 'Riyadh']])
            ->assertJsonCount(1, 'data.services')
            ->assertJsonPath('data.services.0.id', $offered->id)
            ->assertJsonPath('data.services.0.price.amount', '800.00')
            ->assertJsonMissingPath('data.services.0.provider')
            ->assertJsonCount(1, 'data.portfolio')
            ->assertJsonPath('data.portfolio.0.id', $shown->id)
            ->assertJsonPath('data.portfolio.0.caption', 'Wedding')
            ->assertJsonMissingPath('data.legal_name')
            ->assertJsonMissingPath('data.starting_price')
            ->assertJsonStructure(['data' => ['avatar_url', 'cover_url', 'is_favorite', 'categories']]);

        $this->assertNotNull($this->getJson("/api/v1/shared/providers/{$provider->id}")->json('data.avatar_url'));
    }

    public function test_hidden_providers_are_not_found(): void
    {
        $draft = $this->provider('Draft', attributes: ['status' => ProviderStatus::Submitted]);

        $this->getJson("/api/v1/shared/providers/{$draft->id}")->assertNotFound()->assertJsonPath('code', 'NOT_FOUND');
        $this->getJson('/api/v1/shared/providers/999')->assertNotFound();
    }

    public function test_service_details_with_inclusions_and_addons_the_provider_offers(): void
    {
        $category = $this->category();
        $service = $this->service($category, 'Event photography', '1000.00', ['description' => ['en' => 'Full day', 'ar' => 'يوم كامل']]);
        $other = $this->service($category, 'Other');
        $service->inclusions()->create(['text' => ['en' => 'Setup', 'ar' => 'تجهيز'], 'sort' => 1]);
        $album = $service->inclusions()->create(['text' => ['en' => 'Album', 'ar' => 'ألبوم'], 'sort' => 2]);

        $provider = $this->provider('Noura');
        $offered = $this->offer($provider, $service);
        $otherOffered = $this->offer($provider, $other);
        $offered->inclusionSettings()->create(['service_inclusion_id' => $album->id, 'enabled' => false]);

        $addon = fn (string $en, array $services, array $attributes = []) => tap(
            Addon::query()->create(['name' => ['en' => $en, 'ar' => $en], ...$attributes]),
            fn (Addon $addon) => $addon->services()->sync(collect($services)->pluck('id')),
        );
        $hour = $provider->addons()->create(['addon_id' => $addon('Extra hour', [$service])->id, 'price' => '200.00']);
        $hour->services()->sync([$offered->id]);
        $paused = $provider->addons()->create(['addon_id' => $addon('Paused', [$service])->id, 'price' => '1.00', 'is_active' => false]);
        $paused->services()->sync([$offered->id]);
        $retired = $provider->addons()->create(['addon_id' => $addon('Retired', [$service], ['is_active' => false])->id, 'price' => '1.00']);
        $retired->services()->sync([$offered->id]);
        $elsewhere = $provider->addons()->create(['addon_id' => $addon('Other service', [$service, $other])->id, 'price' => '1.00']);
        $elsewhere->services()->sync([$otherOffered->id]);
        $unlinked = $provider->addons()->create(['addon_id' => $addon('Not in catalog for it', [$other])->id, 'price' => '1.00']);
        $unlinked->services()->sync([$offered->id]);

        $this->withHeader('Accept-Language', 'en')->getJson("/api/v1/shared/providers/{$provider->id}/services/{$offered->id}")
            ->assertOk()
            ->assertJsonPath('data.service.name', 'Event photography')
            ->assertJsonPath('data.description', 'Full day')
            ->assertJsonPath('data.price', ['amount' => '1000.00', 'currency' => 'SAR'])
            ->assertJsonPath('data.inclusions', ['Setup'])
            ->assertJsonCount(1, 'data.addons')
            ->assertJsonPath('data.addons.0.id', $hour->id)
            ->assertJsonPath('data.addons.0.name', 'Extra hour')
            ->assertJsonPath('data.addons.0.price.amount', '200.00')
            ->assertJsonPath('data.provider.id', $provider->id)
            ->assertJsonPath('data.provider.display_name', 'Noura');
    }

    public function test_service_price_is_null_when_no_price_is_set(): void
    {
        $provider = $this->provider();
        $offered = $this->offer($provider, $this->service($this->category(), guidePrice: null));

        $this->getJson("/api/v1/shared/providers/{$provider->id}/services/{$offered->id}")
            ->assertOk()->assertJsonPath('data.price', null);
    }

    public function test_service_details_404_for_a_hidden_or_mismatched_service(): void
    {
        $category = $this->category();
        $provider = $this->provider('A');
        $paused = $this->offer($provider, $this->service($category), attributes: ['is_active' => false]);
        $theirs = $this->offer($this->provider('B'), $this->service($category, 'Theirs'));
        $hiddenProvider = $this->provider('C', attributes: ['status' => ProviderStatus::Suspended]);
        $hiddens = $this->offer($hiddenProvider, $this->service($category, 'Hidden'));

        $this->getJson("/api/v1/shared/providers/{$provider->id}/services/{$paused->id}")->assertNotFound();
        $this->getJson("/api/v1/shared/providers/{$provider->id}/services/{$theirs->id}")->assertNotFound();
        $this->getJson("/api/v1/shared/providers/{$hiddenProvider->id}/services/{$hiddens->id}")->assertNotFound();
    }
}
