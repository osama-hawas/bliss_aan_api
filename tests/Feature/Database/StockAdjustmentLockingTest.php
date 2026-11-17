<?php

namespace Tests\Feature\Database;

use App\Enums\ErrorCode;
use App\Enums\StockAdjustmentReason;
use App\Exceptions\ApiException;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Store\Admin\StockAdjustments;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\UsesCommittedRows;
use Tests\TestCase;

/**
 * Slice 18 (S18, D274) with two real MySQL sessions: a staff adjustment waits for the variant row held by a
 * concurrent writer (S9b's deduction at confirmation, or another adjustment), then sees the new count and answers
 * 409 STOCK_CHANGED instead of overwriting it.
 */
class StockAdjustmentLockingTest extends TestCase
{
    use UsesCommittedRows;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCommittedRows();
    }

    protected function tearDown(): void
    {
        $this->tearDownCommittedRows();
        parent::tearDown();
    }

    public function test_an_adjustment_waits_for_a_sale_then_refuses_the_stale_count(): void
    {
        $admin = User::factory()->admin()->create();
        $variant = ProductVariant::factory()->create(['stock' => 5]);

        // The other session is a sale holding the variant row (S9b `fulfil`, D207) and deducting 2.
        $other = DB::connection('other_session');
        $other->beginTransaction();
        $other->select('select id from product_variants where id = ? for update', [$variant->id]);
        $other->update('update product_variants set stock = stock - 2 where id = ?', [$variant->id]);

        $adjust = fn () => app(StockAdjustments::class)->adjust($variant->id, 8, 5, StockAdjustmentReason::Restock, null, $admin);

        $this->shortLockWait();
        $this->assertWaits($adjust);
        $other->commit();

        try {
            $adjust();
            $this->fail('A stale count overwrote the sale.');
        } catch (ApiException $e) {
            $this->assertSame(ErrorCode::StockChanged, $e->errorCode);
        }
        $this->assertSame(3, $variant->refresh()->stock);

        app(StockAdjustments::class)->adjust($variant->id, 8, 3, StockAdjustmentReason::Restock, null, $admin);
        $this->assertSame(8, $variant->refresh()->stock);
        $this->assertDatabaseHas('stock_movements', ['product_variant_id' => $variant->id, 'change' => 5, 'stock_before' => 3, 'stock_after' => 8]);
    }
}
