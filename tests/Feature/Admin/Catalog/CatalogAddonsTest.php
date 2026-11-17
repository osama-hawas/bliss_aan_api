<?php

namespace Tests\Feature\Admin\Catalog;

use App\Models\Addon;
use App\Models\ProviderAddon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsAdminCatalog;
use Tests\Concerns\BuildsServiceRequests;
use Tests\TestCase;

/**
 * Slice 16 §4.3: catalog add-ons. No admin price (providers price them, Slice 3); linked to services through
 * `addon_service` only (D179); stopped, never deleted (D180); ordered by `addons.sort` (D183b).
 */
class CatalogAddonsTest extends TestCase
{
    use BuildsAdminCatalog, BuildsServiceRequests, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMarket();
    }

    public function test_create_links_services_and_has_no_price(): void
    {
        $this->catalogPost('/addons', ['name' => $this->both('Album'), 'service_ids' => [$this->photo->id], 'price' => '99'])
            ->assertCreated()
            ->assertJsonPath('message', 'Add-on saved.')
            ->assertJsonPath('data.services.0.id', $this->photo->id)
            ->assertJsonPath('data.services_count', 1)
            ->assertJsonPath('data.providers_count', 0)
            ->assertJsonPath('data.sort', 1)
            ->assertJsonMissingPath('data.price');

        $this->catalogPost('/addons', ['name' => $this->both('Drone'), 'service_ids' => [999999]])->assertUnprocessable()->assertJsonValidationErrors(['service_ids.0']);
        $this->catalogPost('/addons', ['name' => $this->both('Drone'), 'service_ids' => [$this->photo->id, $this->photo->id]])->assertUnprocessable();
        $this->assertSame(1, Addon::query()->count());
        $this->assertCount(1, $this->audits('catalog_addon_created'));
    }

    public function test_update_relinks_services_and_keeps_provider_rows(): void
    {
        $addon = $this->catalogAddon('Album');
        $provider = $this->matchingProvider();
        $priced = $this->priceAddon($provider, $addon);
        $video = $this->service($this->photo->category, 'Video');

        $this->catalogPut("/addons/{$addon->id}", ['service_ids' => [$video->id], 'description' => $this->both('Printed album')])
            ->assertOk()
            ->assertJsonPath('data.services.0.id', $video->id)
            ->assertJsonPath('data.description.en', 'Printed album')
            ->assertJsonPath('data.providers_count', 1);

        $this->assertModelExists($priced);
        $this->assertSame('150.00', (string) $priced->refresh()->price);
        $audit = $this->audits('catalog_addon_updated')->sole();
        $this->assertSame([$this->photo->id], $audit->old['service_ids']);
        $this->assertSame([$video->id], $audit->new['service_ids']);
    }

    public function test_stop_hides_it_from_new_requests_and_setup_and_activation_restores_it(): void
    {
        $addon = $this->catalogAddon('Album');
        $provider = $this->matchingProvider();
        $this->priceAddon($provider, $addon);
        $request = $this->created($this->openBody(['addon_ids' => [$addon->id]]));

        $this->catalogPost("/addons/{$addon->id}/stop", ['reason' => 'Supplier issue'])->assertOk()->assertJsonPath('data.status', 'inactive');
        $this->getJson('/api/v1/shared/services')->assertOk()->assertJsonCount(0, 'data.0.addons');
        $this->actingAsApi($provider->user)->getJson('/api/v1/provider/catalog')->assertOk()->assertJsonCount(0, 'data.0.addons');
        $this->submit($this->openBody(['addon_ids' => [$addon->id]]))->assertUnprocessable()->assertJsonValidationErrors(['addon_ids']);

        // The request already sent keeps its add-on snapshot (D180).
        $this->assertSame([$addon->id], $request->addons()->pluck('addon_id')->all());
        $this->assertSame(1, ProviderAddon::query()->where('addon_id', $addon->id)->count());

        $this->catalogPost("/addons/{$addon->id}/activate")->assertOk()->assertJsonPath('data.status', 'active');
        $this->getJson('/api/v1/shared/services')->assertOk()->assertJsonCount(1, 'data.0.addons');
        $this->assertSame('Supplier issue', $this->audits('catalog_addon_stopped')->sole()->reason);
    }

    public function test_the_admin_order_is_the_order_everywhere(): void
    {
        $album = $this->catalogAddon('Album');
        $drone = $this->catalogAddon('Drone');
        $album->update(['sort' => 1]);
        $drone->update(['sort' => 2]);

        $this->catalogPost("/addons/{$drone->id}/move", ['direction' => 'up'])->assertOk()->assertJsonPath('data.sort', 1);
        $this->catalogPost("/addons/{$drone->id}/move", ['direction' => 'up'])->assertConflict();

        $this->withHeader('Accept-Language', 'en')->getJson('/api/v1/shared/services')->assertOk()
            ->assertJsonPath('data.0.addons.0.name', 'Drone')
            ->assertJsonPath('data.0.addons.1.name', 'Album');
        $this->catalogGet('/addons')->assertOk()->assertJsonPath('data.0.id', $drone->id);
        $this->catalogGet("/addons?filter[service_id]={$this->photo->id}")->assertOk()->assertJsonCount(2, 'data');
        $this->catalogGet('/addons?q=dro')->assertOk()->assertJsonCount(1, 'data');
    }
}
