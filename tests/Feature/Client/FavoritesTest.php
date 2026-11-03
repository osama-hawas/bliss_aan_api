<?php

namespace Tests\Feature\Client;

use App\Enums\ProviderStatus;
use App\Models\Favorite;
use App\Models\User;
use App\Models\UserDevice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCatalog;
use Tests\TestCase;

class FavoritesTest extends TestCase
{
    use BuildsCatalog, RefreshDatabase;

    private const URL = '/api/v1/client/favorites';

    public function test_add_and_remove_are_idempotent(): void
    {
        $client = User::factory()->client()->create();
        $provider = $this->provider();
        $service = $this->offer($provider, $this->service($this->category()));

        $this->actingAsApi($client)->putJson(self::URL."/provider/{$provider->id}")
            ->assertOk()->assertJsonPath('data', ['type' => 'provider', 'id' => $provider->id, 'is_favorite' => true]);
        $this->putJson(self::URL."/provider/{$provider->id}")->assertOk();
        $this->putJson(self::URL."/provider_service/{$service->id}")->assertOk();
        $this->assertSame(2, Favorite::count());

        $this->deleteJson(self::URL."/provider/{$provider->id}")
            ->assertOk()->assertJsonPath('data.is_favorite', false);
        $this->deleteJson(self::URL."/provider/{$provider->id}")->assertOk();
        $this->assertSame(1, Favorite::count());
    }

    public function test_cannot_favorite_hidden_or_missing_items(): void
    {
        $client = User::factory()->client()->create();
        $hidden = $this->provider(attributes: ['status' => ProviderStatus::Submitted]);
        $paused = $this->offer($this->provider('B'), $this->service($this->category()), attributes: ['is_active' => false]);

        $this->actingAsApi($client)->putJson(self::URL."/provider/{$hidden->id}")->assertNotFound();
        $this->putJson(self::URL."/provider_service/{$paused->id}")->assertNotFound();
        $this->putJson(self::URL.'/provider/999')->assertNotFound();
        $this->putJson(self::URL.'/service/1')->assertNotFound();
        $this->assertSame(0, Favorite::count());
    }

    public function test_list_shows_visible_favorites_newest_first_and_keeps_hidden_ones(): void
    {
        $client = User::factory()->client()->create();
        $category = $this->category();
        $provider = $this->provider('Noura', [$this->city()]);
        $service = $this->offer($provider, $this->service($category), '700.00');
        $later = $this->provider('Later');
        $this->offer($later, $this->service($category, 'Other'));

        $this->actingAsApi($client);
        foreach (["provider/{$provider->id}", "provider_service/{$service->id}", "provider/{$later->id}"] as $path) {
            $this->putJson(self::URL."/{$path}")->assertOk();
        }

        $this->getJson(self::URL)->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.type', 'provider')
            ->assertJsonPath('data.0.provider.id', $later->id)
            ->assertJsonPath('data.0.provider.is_favorite', true)
            ->assertJsonPath('data.0.provider.starting_price.amount', '1000.00')
            ->assertJsonPath('data.1.type', 'provider_service')
            ->assertJsonPath('data.1.provider', null)
            ->assertJsonPath('data.1.provider_service.price.amount', '700.00')
            ->assertJsonPath('data.1.provider_service.provider.id', $provider->id)
            ->assertJsonPath('data.1.provider_service.is_favorite', true);

        $this->getJson(self::URL.'?filter[type]=provider_service')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson(self::URL.'?filter[type]=nope')->assertUnprocessable();

        $later->update(['status' => ProviderStatus::Suspended]);
        $this->getJson(self::URL)->assertOk()->assertJsonCount(2, 'data');
        $this->assertSame(3, Favorite::count()); // history is kept

        // Removing a no-longer-visible favorite still works.
        $this->deleteJson(self::URL."/provider/{$later->id}")->assertOk();
        $this->assertSame(2, Favorite::count());
    }

    public function test_favorites_are_private_to_each_client(): void
    {
        $provider = $this->provider();
        $this->offer($provider, $this->service($this->category()));
        $this->actingAsApi(User::factory()->client()->create())->putJson(self::URL."/provider/{$provider->id}")->assertOk();

        $this->actingAsApi(User::factory()->client()->create())->getJson(self::URL)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/shared/providers/{$provider->id}")->assertOk()->assertJsonPath('data.is_favorite', false);
    }

    public function test_only_clients_can_use_favorites(): void
    {
        $this->getJson(self::URL)->assertUnauthorized();
        $this->actingAsApi(User::factory()->provider()->create())->getJson(self::URL)->assertForbidden();
        $this->actingAsApi(User::factory()->client()->suspended()->create())->getJson(self::URL)
            ->assertForbidden()->assertJsonPath('code', 'ACCOUNT_SUSPENDED');
    }

    public function test_public_endpoints_mark_favorites_for_a_signed_in_client(): void
    {
        $client = User::factory()->client()->create();
        $provider = $this->provider();
        $service = $this->offer($provider, $this->service($this->category()));
        Favorite::query()->create(['user_id' => $client->id, 'favoritable_type' => 'provider', 'favoritable_id' => $provider->id]);
        Favorite::query()->create(['user_id' => $client->id, 'favoritable_type' => 'provider_service', 'favoritable_id' => $service->id]);

        $this->getJson('/api/v1/shared/providers')->assertOk()->assertJsonPath('data.0.is_favorite', false);

        $this->actingAsApi($client);
        $this->getJson('/api/v1/shared/providers')->assertOk()->assertJsonPath('data.0.is_favorite', true);
        $this->getJson("/api/v1/shared/providers/{$provider->id}")->assertOk()
            ->assertJsonPath('data.is_favorite', true)
            ->assertJsonPath('data.services.0.is_favorite', true);
        $this->getJson("/api/v1/shared/providers/{$provider->id}/services/{$service->id}")->assertOk()->assertJsonPath('data.is_favorite', true);
    }

    public function test_public_endpoints_reject_a_bad_or_closed_token(): void
    {
        $client = User::factory()->client()->create();
        $token = $this->tokenFor($client);

        $this->withApiToken('not-a-token')->getJson('/api/v1/shared/categories')->assertUnauthorized();

        UserDevice::query()->update(['revoked_at' => now()]);
        $this->withApiToken($token)->getJson('/api/v1/shared/categories')->assertUnauthorized();
    }
}
