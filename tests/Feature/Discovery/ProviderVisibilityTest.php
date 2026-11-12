<?php

namespace Tests\Feature\Discovery;

use App\Enums\ProviderStatus;
use App\Enums\UserStatus;
use App\Models\Favorite;
use App\Models\Provider;
use App\Models\ProviderService;
use App\Models\Service;
use App\Models\User;
use App\Services\Providers\ProviderSetup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCatalog;
use Tests\TestCase;

/**
 * D75 (amends D34): a listed provider is shown to clients only while at least one of her services is offered with an
 * execution place (D74). One is enough; D73 confirmation does not count; her status never changes because of it.
 */
class ProviderVisibilityTest extends TestCase
{
    use BuildsCatalog, RefreshDatabase;

    private const FAVORITES = '/api/v1/client/favorites';

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = $this->service($this->category());
    }

    /**
     * A listed provider whose only service has no execution place (a row from before D74).
     */
    private function placeless(string $name = 'Placeless'): Provider
    {
        $provider = $this->provider($name, attributes: ['is_featured' => true]);
        $this->offer($provider, $this->service, attributes: ['at_event_location' => false, 'at_provider_location' => false]);

        return $provider;
    }

    /**
     * @return list<int>
     */
    private function searchIds(): array
    {
        return array_column($this->getJson('/api/v1/shared/providers')->assertOk()->json('data'), 'id');
    }

    /**
     * @return list<int>
     */
    private function featuredIds(): array
    {
        return array_column($this->getJson('/api/v1/shared/home')->assertOk()->json('data.featured_providers'), 'id');
    }

    private function assertShown(Provider $provider): void
    {
        $this->getJson("/api/v1/shared/providers/{$provider->id}")->assertOk()->assertJsonPath('data.id', $provider->id);
        $this->assertContains($provider->id, $this->searchIds());
        $this->assertContains($provider->id, $this->featuredIds());
        $this->assertTrue(Provider::query()->visibleToClients()->whereKey($provider->id)->exists());
    }

    private function assertHidden(Provider $provider): void
    {
        $this->getJson("/api/v1/shared/providers/{$provider->id}")->assertNotFound()->assertJsonPath('code', 'NOT_FOUND');
        $this->assertNotContains($provider->id, $this->searchIds());
        $this->assertNotContains($provider->id, $this->featuredIds());
        $this->assertFalse(Provider::query()->visibleToClients()->whereKey($provider->id)->exists());
        // Hidden from clients only: still listed and administratively active.
        $this->assertTrue(Provider::query()->listed()->whereKey($provider->id)->exists());
        $this->assertSame(ProviderStatus::Active, $provider->fresh()->status);
    }

    public function test_a_provider_without_an_eligible_service_is_hidden_but_stays_active(): void
    {
        $placeless = $this->placeless();
        $stopped = $this->provider('Stopped', attributes: ['is_featured' => true]);
        $this->offer($stopped, $this->service, attributes: ['is_active' => false]);
        $empty = $this->provider('No services', attributes: ['is_featured' => true]);

        foreach ([$placeless, $stopped, $empty] as $provider) {
            $this->assertHidden($provider);
        }
    }

    public function test_one_eligible_service_is_enough(): void
    {
        $provider = $this->placeless('Mixed');
        $this->offer($provider, $this->service($this->category('Makeup'), 'Bridal makeup'), attributes: ['at_event_location' => false, 'at_provider_location' => true]);
        $this->offer($provider, $this->service($this->category('Cakes'), 'Cakes'), attributes: ['is_active' => false]);

        $this->assertShown($provider);
        // Her profile still lists only the offered service.
        $this->getJson("/api/v1/shared/providers/{$provider->id}")->assertJsonCount(1, 'data.services');
    }

    public function test_an_unconfirmed_event_location_service_keeps_her_visible(): void
    {
        // D73(7): confirmation gates open-request matching only.
        $provider = $this->provider('Unconfirmed', attributes: ['is_featured' => true]);
        $offered = $this->offer($provider, $this->service, attributes: ['at_event_location' => true]);

        $this->assertTrue($offered->fresh()->eventLocationNeedsReview());
        $this->assertFalse(ProviderService::query()->openRequestCapable()->whereKey($offered->id)->exists());
        $this->assertShown($provider);
    }

    public function test_an_at_my_location_only_service_keeps_her_visible_without_confirmation(): void
    {
        $provider = $this->provider('Studio', attributes: ['is_featured' => true]);
        $this->offer($provider, $this->service, attributes: ['at_event_location' => false, 'at_provider_location' => true]);

        $this->assertShown($provider);
    }

    public function test_visibility_follows_her_services_without_changing_her_status(): void
    {
        $provider = $this->provider('Noura', attributes: ['is_featured' => true]);
        $offered = $this->offer($provider, $this->service, attributes: ['at_event_location' => false, 'at_provider_location' => true]);
        $this->assertShown($provider);

        // She stops her only service.
        app(ProviderSetup::class)->updateService($offered, ['is_active' => false]);
        $this->assertHidden($provider);

        // She offers it again.
        app(ProviderSetup::class)->addService($provider->fresh(), $this->service->id);
        $this->assertShown($provider);

        // The admin retires the catalog service: no eligible service is left.
        $this->service->update(['is_active' => false]);
        $this->assertHidden($provider);

        $this->service->update(['is_active' => true]);
        $this->assertShown($provider);
    }

    public function test_a_service_without_a_place_regains_visibility_once_it_has_one(): void
    {
        $provider = $this->placeless();
        $this->assertHidden($provider);

        app(ProviderSetup::class)->setEventLocation($provider->services()->sole(), true);

        $this->assertShown($provider);
    }

    public function test_provider_favorites_follow_visibility(): void
    {
        $client = User::factory()->client()->create();
        $hidden = $this->placeless();
        $provider = $this->provider('Noura');
        $offered = $this->offer($provider, $this->service);

        $this->actingAsApi($client)->putJson(self::FAVORITES."/provider/{$hidden->id}")->assertNotFound();
        $this->putJson(self::FAVORITES."/provider/{$provider->id}")->assertOk();
        $this->putJson(self::FAVORITES."/provider_service/{$offered->id}")->assertOk();
        $this->assertSame(2, Favorite::count());
        $this->getJson(self::FAVORITES)->assertOk()->assertJsonCount(2, 'data');

        // Her only service stops: both favorites are kept but no longer listed, and she cannot be added again.
        app(ProviderSetup::class)->updateService($offered, ['is_active' => false]);
        $this->getJson(self::FAVORITES)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson(self::FAVORITES.'?filter[type]=provider')->assertOk()->assertJsonCount(0, 'data');
        $this->deleteJson(self::FAVORITES."/provider/{$provider->id}");
        $this->putJson(self::FAVORITES."/provider/{$provider->id}")->assertNotFound();
        $this->assertSame(1, Favorite::count());

        // Offered again: she can be favorited and is listed.
        app(ProviderSetup::class)->addService($provider->fresh(), $this->service->id);
        $this->putJson(self::FAVORITES."/provider/{$provider->id}")->assertOk();
        $this->getJson(self::FAVORITES.'?filter[type]=provider')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.provider.id', $provider->id);
        $this->getJson(self::FAVORITES)->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_a_suspended_provider_drops_out_of_favorites_without_a_status_change(): void
    {
        $client = User::factory()->client()->create();
        $ownerSuspended = $this->provider('Owner suspended');
        $ownerOffered = $this->offer($ownerSuspended, $this->service);
        $adminSuspended = $this->provider('Provider suspended');
        $adminOffered = $this->offer($adminSuspended, $this->service);
        $visible = $this->provider('Noura');
        $this->offer($visible, $this->service);

        $this->actingAsApi($client);
        foreach ([$ownerSuspended, $adminSuspended, $visible] as $provider) {
            $this->putJson(self::FAVORITES."/provider/{$provider->id}")->assertOk();
        }
        $this->putJson(self::FAVORITES."/provider_service/{$ownerOffered->id}")->assertOk();
        $this->putJson(self::FAVORITES."/provider_service/{$adminOffered->id}")->assertOk();
        $this->getJson(self::FAVORITES)->assertOk()->assertJsonPath('meta.total', 5);

        // Each still has an eligible service: only the suspension hides her.
        User::query()->whereKey($ownerSuspended->user_id)->update(['status' => UserStatus::Suspended]);
        $adminSuspended->update(['status' => ProviderStatus::Suspended]);
        $this->assertTrue($ownerOffered->fresh()->hasExecutionPlace() && $adminOffered->fresh()->hasExecutionPlace());

        $this->getJson(self::FAVORITES)->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.type', 'provider')
            ->assertJsonPath('data.0.provider.id', $visible->id);
        $this->getJson(self::FAVORITES.'?filter[type]=provider')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson(self::FAVORITES.'?filter[type]=provider_service')->assertOk()->assertJsonPath('meta.total', 0);

        // A favorite of either is kept, but she cannot be added again; nor can her service.
        $this->deleteJson(self::FAVORITES."/provider/{$ownerSuspended->id}")->assertOk();
        $this->deleteJson(self::FAVORITES."/provider/{$adminSuspended->id}")->assertOk();
        foreach ([$ownerSuspended, $adminSuspended] as $provider) {
            $this->putJson(self::FAVORITES."/provider/{$provider->id}")->assertNotFound();
        }
        $this->putJson(self::FAVORITES."/provider_service/{$ownerOffered->id}")->assertNotFound();
        $this->putJson(self::FAVORITES."/provider_service/{$adminOffered->id}")->assertNotFound();
        $this->getJson(self::FAVORITES)->assertOk()->assertJsonPath('meta.total', 1);
        $this->assertSame(3, Favorite::count());

        // Filtering never touches her status.
        $this->assertSame(ProviderStatus::Active, $ownerSuspended->fresh()->status);
        $this->assertSame(UserStatus::Suspended, $ownerSuspended->user->fresh()->status);
        $this->assertSame(ProviderStatus::Suspended, $adminSuspended->fresh()->status);
        $this->assertSame(ProviderStatus::Active, $visible->fresh()->status);
    }

    public function test_an_inactive_category_hides_a_provider_whose_only_service_is_in_it(): void
    {
        $category = $this->category('Makeup');
        $provider = $this->provider('Glow', attributes: ['is_featured' => true]);
        $offered = $this->offer($provider, $this->service($category, 'Bridal makeup'), attributes: ['at_event_location' => false, 'at_provider_location' => true]);
        $this->assertShown($provider);
        $this->getJson("/api/v1/shared/providers/{$provider->id}/services/{$offered->id}")->assertOk();

        $category->update(['is_active' => false]);

        $this->assertHidden($provider);
        $this->getJson("/api/v1/shared/providers/{$provider->id}/services/{$offered->id}")->assertNotFound();
        $this->getJson("/api/v1/shared/providers?filter[category_id]={$category->id}")->assertOk()->assertJsonPath('meta.total', 0);
        // The service row itself is untouched: only the category was switched off.
        $this->assertTrue($offered->fresh()->is_active);
        $this->assertTrue($offered->fresh()->hasExecutionPlace());

        $category->update(['is_active' => true]);
        $this->assertShown($provider);
    }

    public function test_no_search_filter_or_query_exposes_a_hidden_provider(): void
    {
        $riyadh = $this->city();
        $category = $this->category('Photography');
        $service = $this->service($category, 'Event photography');
        $match = ['is_verified' => true, 'rating_avg' => 4.9, 'rating_count' => 10, 'is_featured' => true];

        $visible = $this->provider('Visible Lens', [$riyadh], $match);
        $this->offer($visible, $service, '500.00');
        // Hidden, but matching every filter below: no execution place (D74) …
        $placeless = $this->provider('Placeless Lens', [$riyadh], $match);
        $this->offer($placeless, $service, '100.00', ['at_event_location' => false, 'at_provider_location' => false]);
        // … or an eligible service under a suspended owner account.
        $suspended = $this->provider('Suspended Lens', [$riyadh], $match);
        $this->offer($suspended, $service, '100.00');
        User::query()->whereKey($suspended->user_id)->update(['status' => UserStatus::Suspended]);

        $queries = [
            '',
            "?filter[city_id]={$riyadh->id}",
            "?filter[category_id]={$category->id}",
            "?filter[service_id]={$service->id}",
            '?filter[min_rating]=4.5',
            '?filter[verified]=1',
            '?q=Lens',
            '?q=Placeless',
            '?q=Suspended',
            '?q=photography',
            "?filter[city_id]={$riyadh->id}&filter[category_id]={$category->id}&filter[service_id]={$service->id}&filter[min_rating]=4.5&filter[verified]=1&q=Lens",
        ];
        foreach ($queries as $query) {
            foreach (['', '-rating', 'price', '-price', '-activated_at'] as $sort) {
                $url = '/api/v1/shared/providers'.$query.($sort === '' ? '' : ($query === '' ? '?' : '&')."sort={$sort}");
                $response = $this->getJson($url)->assertOk();
                $ids = array_column($response->json('data'), 'id');
                $this->assertNotContains($placeless->id, $ids, $url);
                $this->assertNotContains($suspended->id, $ids, $url);
                $this->assertSame(count($ids), $response->json('meta.total'), $url);
            }
        }
        // Only the visible provider is found by the filters she matches; the hidden ones match the provider-name queries alone.
        $this->assertSame([$visible->id], array_column($this->getJson('/api/v1/shared/providers?q=Lens')->json('data'), 'id'));
        $this->assertSame(0, $this->getJson('/api/v1/shared/providers?q=Placeless')->json('meta.total'));
        $this->assertSame([$visible->id], array_column($this->getJson("/api/v1/shared/home?city_id={$riyadh->id}")->json('data.featured_providers'), 'id'));
    }

    public function test_the_favorites_total_counts_visible_providers_only(): void
    {
        $client = User::factory()->client()->create();
        $visible = [];
        foreach (['A', 'B', 'C'] as $name) {
            $provider = $this->provider("Visible {$name}");
            $this->offer($provider, $this->service);
            $visible[] = $provider;
        }
        $hidden = [$this->placeless('Hidden placeless')];
        $stopped = $this->provider('Hidden stopped');
        $stoppedOffer = $this->offer($stopped, $this->service);
        $hidden[] = $stopped;

        $this->actingAsApi($client);
        foreach ([...$visible, ...$hidden] as $provider) {
            // Saved while visible; the placeless one is given a place for the moment.
            $provider->services()->update(['at_provider_location' => true]);
            $this->putJson(self::FAVORITES."/provider/{$provider->id}")->assertOk();
        }
        $hidden[0]->services()->update(['at_provider_location' => false]);
        app(ProviderSetup::class)->updateService($stoppedOffer->fresh(), ['is_active' => false]);
        $this->assertSame(5, Favorite::count());

        $this->getJson(self::FAVORITES.'?per_page=2')->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.last_page', 2);
        $page2 = $this->getJson(self::FAVORITES.'?per_page=2&page=2')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 3);

        $listed = [
            ...array_column($this->getJson(self::FAVORITES.'?per_page=2')->json('data'), 'provider'),
            ...array_column($page2->json('data'), 'provider'),
        ];
        $this->assertEqualsCanonicalizing(array_map(fn (Provider $p) => $p->id, $visible), array_column($listed, 'id'));
        $this->getJson(self::FAVORITES.'?filter[type]=provider')->assertOk()->assertJsonCount(3, 'data')->assertJsonPath('meta.total', 3)->assertJsonPath('meta.last_page', 1);
        foreach ($hidden as $provider) {
            $this->assertSame(ProviderStatus::Active, $provider->fresh()->status);
        }
    }
}
