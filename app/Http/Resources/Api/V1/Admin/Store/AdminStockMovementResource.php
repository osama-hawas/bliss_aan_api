<?php

namespace App\Http\Resources\Api\V1\Admin\Store;

use App\Http\Resources\Concerns\FormatsApiValues;
use App\Models\StockMovement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One stock history row (D279).
 *
 * @mixin StockMovement
 */
class AdminStockMovementResource extends JsonResource
{
    use FormatsApiValues;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'variant' => ['id' => $this->product_variant_id, 'sku' => $this->variant?->sku],
            'source' => $this->source->value,
            'source_label' => $this->source->label(),
            'reason_code' => $this->reason_code?->value,
            'reason_label' => $this->reason_code?->label(),
            'note' => $this->note,
            'change' => $this->change,
            'stock_before' => $this->stock_before,
            'stock_after' => $this->stock_after,
            'store_order_id' => $this->store_order_id,
            'actor' => $this->actor ? ['id' => $this->actor->id, 'name' => $this->actor->name] : null,
            'created_at' => $this->dateTime($this->created_at),
        ];
    }
}
