<?php

namespace Tests\Feature\Admin\Catalog;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsAdminCatalog;
use Tests\Concerns\BuildsCatalog;
use Tests\TestCase;

/**
 * Slice 16 access (D182): every `admin/catalog/*` route needs a staff token with `catalog.manage`; nothing can be deleted.
 */
class CatalogAccessTest extends TestCase
{
    use BuildsAdminCatalog, BuildsCatalog, RefreshDatabase;

    private const LISTS = ['/categories', '/services', '/addons', '/event-types'];

    public function test_guests_get_401(): void
    {
        foreach (self::LISTS as $path) {
            $this->getJson(self::CATALOG.$path)->assertUnauthorized();
        }
    }

    public function test_clients_and_providers_get_403(): void
    {
        foreach ([User::factory()->client()->create(), User::factory()->provider()->create()] as $user) {
            $this->actingAsApi($user)->getJson(self::CATALOG.'/categories')->assertForbidden();
        }
    }

    public function test_staff_without_catalog_manage_get_403_on_reads_and_writes(): void
    {
        $officer = $this->staffWithoutCatalog();
        $category = $this->category();
        $service = $this->service($category);

        foreach (self::LISTS as $path) {
            $this->actingAsApi($officer)->getJson(self::CATALOG.$path)->assertForbidden();
        }
        $this->actingAsApi($officer)->postJson(self::CATALOG.'/categories', ['name' => $this->both('Flowers')])->assertForbidden();
        $this->actingAsApi($officer)->putJson(self::CATALOG."/services/{$service->id}", ['guide_price' => '5', 'reason' => 'nope'])->assertForbidden();
        $this->actingAsApi($officer)->postJson(self::CATALOG."/services/{$service->id}/stop", ['reason' => 'nope'])->assertForbidden();
        $this->assertSame('1000.00', (string) $service->refresh()->guide_price);
        $this->assertTrue($service->is_active);
    }

    public function test_super_admin_reads_every_list(): void
    {
        foreach (self::LISTS as $path) {
            $this->catalogGet($path)->assertOk()->assertJsonStructure(['data', 'meta' => ['current_page', 'per_page', 'total', 'last_page']]);
        }
    }

    public function test_there_is_no_delete_route(): void
    {
        $category = $this->category();
        $service = $this->service($category);

        $this->asCatalogAdmin()->deleteJson(self::CATALOG."/categories/{$category->id}")->assertStatus(405);
        $this->asCatalogAdmin()->deleteJson(self::CATALOG."/services/{$service->id}")->assertStatus(405);
        $this->assertModelExists($service);
    }

    public function test_unknown_ids_are_404(): void
    {
        $this->catalogGet('/categories/999999')->assertNotFound()->assertJsonPath('code', 'NOT_FOUND');
        $this->catalogGet('/services/999999')->assertNotFound();
        $this->catalogPost('/addons/999999/stop', ['reason' => 'Not offered'])->assertNotFound();
        $this->catalogPost('/event-types/999999/move', ['direction' => 'up'])->assertNotFound();
    }
}
