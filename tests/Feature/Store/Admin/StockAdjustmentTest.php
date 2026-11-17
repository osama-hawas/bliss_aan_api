<?php

namespace Tests\Feature\Store\Admin;

use App\Enums\AdminRole;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsApplications;
use Tests\TestCase;

/**
 * Slice 18 (S18) — stock adjustments with a reason and the stock history (slice-18-contract.md §5.3–§5.4, D274/D279).
 */
class StockAdjustmentTest extends TestCase
{
    use BuildsApplications, RefreshDatabase;

    private const VARIANTS = '/api/v1/admin/product-variants';

    private function officer(): User
    {
        return $this->staff(AdminRole::StoreContentOfficer);
    }

    public function test_adjust_sets_the_count_and_writes_history_and_audit(): void
    {
        $admin = $this->officer();
        $variant = ProductVariant::factory()->create(['stock' => 4]);

        $this->actingAsApi($admin)->postJson(self::VARIANTS."/{$variant->id}/stock", ['stock' => 12, 'expected_stock' => 4, 'reason_code' => 'restock'])
            ->assertOk()
            ->assertJsonPath('data.variants.0.stock', 12)
            ->assertJsonPath('data.history.0.action', 'product_variant.stock_adjusted')
            ->assertJsonPath('data.history.0.old.stock', 4)
            ->assertJsonPath('data.history.0.new.stock', 12);
        $this->actingAsApi($admin)->postJson(self::VARIANTS."/{$variant->id}/stock", ['stock' => 9, 'expected_stock' => 12, 'reason_code' => 'damaged', 'note' => 'Broken box'])->assertOk();

        $this->actingAsApi($admin)->getJson("/api/v1/admin/products/{$variant->product_id}/stock-movements")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.change', -3)
            ->assertJsonPath('data.0.reason_code', 'damaged')
            ->assertJsonPath('data.0.note', 'Broken box')
            ->assertJsonPath('data.0.stock_before', 12)
            ->assertJsonPath('data.0.stock_after', 9)
            ->assertJsonPath('data.0.actor.id', $admin->id)
            ->assertJsonPath('data.1.change', 8)
            ->assertJsonPath('data.1.source', 'adjustment');
        $this->actingAsApi($admin)->getJson("/api/v1/admin/products/{$variant->product_id}/stock-movements?filter[variant_id]=999")->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_a_stale_count_is_refused_with_stock_changed(): void
    {
        $admin = $this->officer();
        $variant = ProductVariant::factory()->create(['stock' => 4]);

        $this->actingAsApi($admin)->postJson(self::VARIANTS."/{$variant->id}/stock", ['stock' => 0, 'expected_stock' => 5, 'reason_code' => 'stocktake'])
            ->assertStatus(409)->assertJsonPath('code', 'STOCK_CHANGED')->assertJsonStructure(['errors' => ['expected_stock']]);
        $this->assertSame(4, $variant->refresh()->stock);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_validation(): void
    {
        $admin = $this->officer();
        $variant = ProductVariant::factory()->create(['stock' => 4]);
        $url = self::VARIANTS."/{$variant->id}/stock";

        $this->actingAsApi($admin)->postJson($url, [])->assertUnprocessable()->assertJsonValidationErrors(['stock', 'expected_stock', 'reason_code']);
        $this->actingAsApi($admin)->postJson($url, ['stock' => -1, 'expected_stock' => 4, 'reason_code' => 'lost'])->assertUnprocessable()->assertJsonValidationErrors(['stock', 'reason_code']);
        $this->actingAsApi($admin)->postJson($url, ['stock' => 2, 'expected_stock' => 4, 'reason_code' => 'other'])->assertUnprocessable()->assertJsonValidationErrors(['note']);
        $this->actingAsApi($admin)->postJson($url, ['stock' => 4, 'expected_stock' => 4, 'reason_code' => 'correction'])->assertUnprocessable()->assertJsonValidationErrors(['stock']);
        $this->actingAsApi($admin)->postJson(self::VARIANTS.'/999999/stock', ['stock' => 1, 'expected_stock' => 0, 'reason_code' => 'restock'])->assertNotFound();
    }

    public function test_a_stopped_variant_can_still_be_counted_and_clients_never_see_the_number(): void
    {
        $admin = $this->officer();
        $variant = ProductVariant::factory()->create(['stock' => 0]);
        ProductVariant::factory()->create(['product_id' => $variant->product_id, 'stock' => 1, 'attributes' => ['color' => ['ar' => 'أحمر', 'en' => 'Red']]]);
        $this->actingAsApi($admin)->postJson(self::VARIANTS."/{$variant->id}/stop", ['reason' => 'Old stock'])->assertOk();

        $this->actingAsApi($admin)->postJson(self::VARIANTS."/{$variant->id}/stock", ['stock' => 3, 'expected_stock' => 0, 'reason_code' => 'stocktake'])->assertOk();
        $this->getJson("/api/v1/shared/store/products/{$variant->product_id}")->assertOk()->assertJsonMissingPath('data.variants.0.stock');
    }
}
