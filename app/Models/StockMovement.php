<?php

namespace App\Models;

use App\Enums\StockAdjustmentReason;
use App\Enums\StockMovementSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One change of a variant's stock (Slice 18, D279). Append-only: never updated or deleted.
 */
class StockMovement extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['product_variant_id', 'source', 'reason_code', 'note', 'change', 'stock_before', 'stock_after', 'store_order_id', 'actor_id'];

    protected function casts(): array
    {
        return [
            'source' => StockMovementSource::class,
            'reason_code' => StockAdjustmentReason::class,
            'change' => 'integer',
            'stock_before' => 'integer',
            'stock_after' => 'integer',
        ];
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
