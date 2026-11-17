<?php

namespace App\Services\Store\Admin;

use App\Enums\ErrorCode;
use App\Enums\StockAdjustmentReason;
use App\Enums\StockMovementSource;
use App\Exceptions\ApiException;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Staff stock changes (Slice 18, D274) and the stock history (D279).
 *
 * Stock is never edited through the variant form: staff set a new count with the count they saw (`expected_stock`)
 * and a reason. The variant row is locked (`FOR UPDATE`) — the same row S9b's `fulfil` locks to deduct stock (D207),
 * so a sale and an adjustment never overwrite each other: if the count moved since she looked, `409 STOCK_CHANGED`.
 * Only that one row is locked, so this never joins a lock cycle with S9b's order → variants (id asc) order.
 */
class StockAdjustments
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function adjust(int $variantId, int $stock, int $expected, StockAdjustmentReason $reason, ?string $note, User $actor): ProductVariant
    {
        if ($reason === StockAdjustmentReason::Other && blank($note)) {
            throw new ApiException(ErrorCode::ValidationFailed, null, ['note' => [__('store.admin.note_required')]]);
        }

        return DB::transaction(function () use ($variantId, $stock, $expected, $reason, $note, $actor) {
            $variant = ProductVariant::query()->lockForUpdate()->findOrFail($variantId);

            if ($variant->stock !== $expected) {
                throw new ApiException(ErrorCode::StockChanged, null, [
                    'expected_stock' => [__('store.admin.stock_now', ['stock' => $variant->stock])],
                ]);
            }
            if ($variant->stock === $stock) {
                throw new ApiException(ErrorCode::ValidationFailed, null, ['stock' => [__('store.admin.stock_unchanged')]]);
            }

            $before = $variant->stock;
            $variant->update(['stock' => $stock]);
            $this->record($variant, $before, StockMovementSource::Adjustment, $actor, $reason, $note);
            $this->audit->log('product_variant.stock_adjusted', $actor, $variant->product, $note ?? $reason->label(),
                ['variant_id' => $variant->id, 'sku' => $variant->sku, 'stock' => $before],
                ['variant_id' => $variant->id, 'sku' => $variant->sku, 'stock' => $stock, 'reason_code' => $reason->value]);

            return $variant->refresh();
        });
    }

    /**
     * Appends one history row for a stock change already written on the locked variant row. Public so S9b's
     * deduction / restoration can record `order` / `order_cancelled` movements after the merge (contract §9).
     */
    public function record(
        ProductVariant $variant,
        int $stockBefore,
        StockMovementSource $source,
        ?User $actor = null,
        ?StockAdjustmentReason $reason = null,
        ?string $note = null,
        ?int $storeOrderId = null,
    ): StockMovement {
        return StockMovement::query()->create([
            'product_variant_id' => $variant->id,
            'source' => $source,
            'reason_code' => $reason,
            'note' => $note,
            'change' => $variant->stock - $stockBefore,
            'stock_before' => $stockBefore,
            'stock_after' => $variant->stock,
            'store_order_id' => $storeOrderId,
            'actor_id' => $actor?->id,
        ]);
    }

    /**
     * A product's stock history, newest first, optionally for one of its variants.
     */
    public function history(Product $product, ?int $variantId, int $perPage): LengthAwarePaginator
    {
        return StockMovement::query()
            ->whereIn('product_variant_id', $product->variants()->select('id'))
            ->when($variantId, fn ($q, int $id) => $q->where('product_variant_id', $id))
            ->with(['variant:id,sku', 'actor:id,name'])
            ->orderByDesc('id')
            ->paginate($perPage);
    }
}
