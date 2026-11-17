<?php

namespace Tests\Feature\Admin\Catalog;

use App\Enums\AdminRole;
use App\Models\EventType;
use App\Models\Package;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\BuildsAdminCatalog;
use Tests\Concerns\BuildsPackages;
use Tests\Concerns\BuildsServiceRequests;
use Tests\TestCase;

/**
 * Slice 24 §4 (OD39): packages in the dashboard — create, update, stop with a reason, activate, move, image, audit;
 * `catalog.manage` only (D182); no price (D350); never deleted (D180).
 */
class CatalogPackagesTest extends TestCase
{
    use BuildsAdminCatalog, BuildsPackages, BuildsServiceRequests, RefreshDatabase;

    private Service $makeup;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMarket();
        $this->makeup = $this->service($this->category('Beauty', 'التجميل'), 'Bridal makeup', '800.00');
    }

    private function body(array $overrides = []): array
    {
        return [
            'name' => $this->both('Bride day', 'يوم العروس'),
            'description' => $this->both('Everything for the big day', 'كل ما تحتاجينه ليومك'),
            'service_ids' => [$this->makeup->id, $this->photo->id],
            'event_type_ids' => [$this->wedding->id],
            ...$overrides,
        ];
    }

    public function test_create_returns_the_package_without_a_price(): void
    {
        $image = $this->catalogImage();

        $response = $this->catalogPost('/packages', $this->body(['image_media_id' => $image->id]))
            ->assertCreated()
            ->assertJsonPath('message', 'Package saved.')
            ->assertJsonPath('data.name', ['ar' => 'يوم العروس', 'en' => 'Bride day'])
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.stop_reason', null)
            ->assertJsonPath('data.sort', 1)
            ->assertJsonPath('data.image.id', $image->id)
            ->assertJsonPath('data.services.0.id', $this->makeup->id)
            ->assertJsonPath('data.services.1.id', $this->photo->id)
            ->assertJsonPath('data.services_count', 2)
            ->assertJsonPath('data.visible_services_count', 2)
            ->assertJsonPath('data.event_types.0.id', $this->wedding->id)
            ->assertJsonPath('data.is_visible_in_app', true)
            ->assertJsonPath('data.requests_count', 0);

        $this->assertArrayNotHasKey('price', $response->json('data'));
        $this->assertArrayNotHasKey('guide_price', $response->json('data'));
        $audit = $this->audits('catalog_package_created')->sole();
        $this->assertSame(Package::query()->sole()->id, $audit->subject_id);
        $this->assertSame([$this->makeup->id, $this->photo->id], $audit->new['service_ids']);
    }

    public function test_validation(): void
    {
        $this->catalogPost('/packages', [])->assertUnprocessable()->assertJsonValidationErrors(['name', 'service_ids']);
        $this->catalogPost('/packages', $this->body(['service_ids' => []]))->assertUnprocessable()->assertJsonValidationErrors(['service_ids']);
        $this->catalogPost('/packages', $this->body(['service_ids' => [999999]]))->assertUnprocessable()->assertJsonValidationErrors(['service_ids.0']);
        $this->catalogPost('/packages', $this->body(['service_ids' => [$this->photo->id, $this->photo->id]]))->assertUnprocessable()->assertJsonValidationErrors(['service_ids.0']);
        $this->catalogPost('/packages', $this->body(['event_type_ids' => [999999]]))->assertUnprocessable()->assertJsonValidationErrors(['event_type_ids.0']);
        $this->catalogPost('/packages', $this->body(['name' => ['en' => 'Only English']]))->assertUnprocessable()->assertJsonValidationErrors(['name.ar']);
        // Someone else's image, or one already used, is refused.
        $other = $this->catalogImage($this->staffWith(AdminRole::SuperAdmin));
        $this->catalogPost('/packages', $this->body(['image_media_id' => $other->id]))->assertUnprocessable()->assertJsonValidationErrors(['image_media_id']);
        $this->assertSame(0, Package::query()->count());
    }

    public function test_a_price_sent_by_mistake_is_ignored(): void
    {
        $id = $this->catalogPost('/packages', $this->body(['price' => '5000.00', 'guide_price' => '5000.00']))->assertCreated()->json('data.id');

        $this->assertArrayNotHasKey('price', Package::query()->findOrFail($id)->getAttributes());
    }

    public function test_update_reorders_services_and_replaces_event_types(): void
    {
        $engagement = EventType::query()->create(['name' => $this->both('Engagement'), 'is_active' => true, 'sort' => 2]);
        $hair = $this->service($this->makeup->category, 'Hair styling', null);
        $id = $this->catalogPost('/packages', $this->body())->json('data.id');

        $this->catalogPut("/packages/{$id}", [])->assertUnprocessable()->assertJsonValidationErrors(['name']);
        $this->catalogPut("/packages/{$id}", ['service_ids' => []])->assertUnprocessable()->assertJsonValidationErrors(['service_ids']);

        $this->catalogPut("/packages/{$id}", ['service_ids' => [$hair->id, $this->makeup->id], 'event_type_ids' => [$engagement->id], 'description' => null])
            ->assertOk()
            ->assertJsonPath('data.services.0.id', $hair->id)
            ->assertJsonPath('data.services.1.id', $this->makeup->id)
            ->assertJsonCount(2, 'data.services')
            ->assertJsonPath('data.event_types.0.id', $engagement->id)
            ->assertJsonCount(1, 'data.event_types')
            ->assertJsonPath('data.description', null)
            ->assertJsonPath('data.name.en', 'Bride day');

        $audit = $this->audits('catalog_package_updated')->sole();
        $this->assertSame([$this->makeup->id, $this->photo->id], $audit->old['service_ids']);
        $this->assertSame([$hair->id, $this->makeup->id], $audit->new['service_ids']);
        $this->assertArrayNotHasKey('name', $audit->new);

        // The same values again write nothing.
        $this->catalogPut("/packages/{$id}", ['service_ids' => [$hair->id, $this->makeup->id]])->assertOk();
        $this->assertCount(1, $this->audits('catalog_package_updated'));
    }

    public function test_image_replaced_and_removed_without_deleting_files(): void
    {
        $first = $this->catalogImage();
        $id = $this->catalogPost('/packages', $this->body(['image_media_id' => $first->id]))->json('data.id');
        $second = $this->catalogImage();

        $this->catalogPut("/packages/{$id}", ['image_media_id' => $second->id])->assertOk()->assertJsonPath('data.image.id', $second->id);
        $this->assertNull($first->refresh()->attachable_id);
        $this->catalogPut("/packages/{$id}", ['image_media_id' => null])->assertOk()->assertJsonPath('data.image', null);
        $this->assertModelExists($first);
        $this->assertModelExists($second);
    }

    public function test_stop_keeps_the_reason_and_activate_clears_it(): void
    {
        $id = $this->catalogPost('/packages', $this->body())->json('data.id');

        $this->catalogPost("/packages/{$id}/stop", [])->assertUnprocessable()->assertJsonValidationErrors(['reason']);
        $this->catalogPost("/packages/{$id}/stop", ['reason' => 'Season over', 'internal_note' => 'Back in spring'])
            ->assertOk()
            ->assertJsonPath('message', 'Package stopped. It is hidden from clients; requests made from it continue unchanged.')
            ->assertJsonPath('data.status', 'inactive')
            ->assertJsonPath('data.stop_reason', 'Season over')
            ->assertJsonPath('data.is_visible_in_app', false);
        $this->catalogPost("/packages/{$id}/stop", ['reason' => 'Again'])->assertConflict()->assertJsonPath('code', 'INVALID_STATE');

        $stopped = $this->audits('catalog_package_stopped')->sole();
        $this->assertSame('Season over', $stopped->reason);
        $this->assertSame('Back in spring', $stopped->new['internal_note']);

        $this->catalogPost("/packages/{$id}/activate")->assertOk()->assertJsonPath('data.status', 'active')->assertJsonPath('data.stop_reason', null);
        $this->catalogPost("/packages/{$id}/activate")->assertConflict();
        $this->assertCount(1, $this->audits('catalog_package_activated'));
        $this->assertModelExists(Package::query()->findOrFail($id));
    }

    public function test_move_list_filters_and_visibility_flags(): void
    {
        $first = $this->package([$this->photo], 'Photo day');
        $second = $this->package([$this->makeup], 'Beauty day', [$this->wedding], ['is_active' => false, 'stop_reason' => 'Paused']);
        $this->makeup->update(['is_active' => false]);

        $this->catalogPost("/packages/{$second->id}/move", ['direction' => 'up'])->assertOk()->assertJsonPath('data.sort', $first->sort);
        $this->catalogPost("/packages/{$second->id}/move", ['direction' => 'up'])->assertConflict();
        $this->assertCount(1, $this->audits('catalog_package_moved'));

        $this->catalogGet('/packages')->assertOk()
            ->assertJsonPath('data.0.id', $second->id)
            ->assertJsonPath('data.0.stop_reason', 'Paused')
            ->assertJsonPath('data.0.services.0.is_visible_in_catalog', false)
            ->assertJsonPath('data.0.visible_services_count', 0)
            ->assertJsonPath('data.1.id', $first->id)
            ->assertJsonPath('meta.total', 2);
        $this->catalogGet('/packages?filter[status]=active')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $first->id);
        $this->catalogGet('/packages?q=beauty')->assertOk()->assertJsonCount(1, 'data');
        $this->catalogGet("/packages?filter[service_id]={$this->photo->id}")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $first->id);
        $this->catalogGet("/packages?filter[event_type_id]={$this->wedding->id}")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $second->id);
        $this->catalogGet('/packages?sort=-name')->assertOk()->assertJsonPath('data.0.id', $first->id);
        $this->catalogGet("/packages/{$first->id}")->assertOk()->assertJsonPath('data.is_visible_in_app', true);
        $this->catalogGet('/packages/999999')->assertNotFound();
    }

    public function test_catalog_manage_is_required_and_there_is_no_delete(): void
    {
        $package = $this->package([$this->photo]);
        $staff = $this->staffWithoutCatalog();

        $this->asCatalogAdmin($staff)->getJson(self::CATALOG.'/packages')->assertForbidden();
        $this->asCatalogAdmin($staff)->postJson(self::CATALOG.'/packages', $this->body())->assertForbidden();
        $this->asCatalogAdmin($staff)->postJson(self::CATALOG."/packages/{$package->id}/stop", ['reason' => 'No'])->assertForbidden();
        $this->actingAsApi($this->client)->getJson(self::CATALOG.'/packages')->assertForbidden();

        $this->assertFalse(collect(Route::getRoutes())->contains(fn ($r) => str_contains($r->uri(), 'admin/catalog/packages') && in_array('DELETE', $r->methods(), true)));
        $this->asCatalogAdmin()->deleteJson(self::CATALOG."/packages/{$package->id}")->assertStatus(405);
        $this->assertModelExists($package);
    }
}
