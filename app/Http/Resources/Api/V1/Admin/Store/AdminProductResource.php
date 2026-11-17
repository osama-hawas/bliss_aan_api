<?php

namespace App\Http\Resources\Api\V1\Admin\Store;

use App\Http\Resources\Concerns\FormatsApiValues;
use App\Models\Product;
use App\Services\Media\MediaService;
use App\Services\Store\Admin\ProductManagement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A product row in the dashboard list (contract §5.2). `low_stock` is null while `store.low_stock_threshold` is not
 * set (D278). `sort` comes from S9b's column (D209) and reads 0 until it exists.
 *
 * @mixin Product
 */
class AdminProductResource extends JsonResource
{
    use FormatsApiValues;

    public function toArray(Request $request): array
    {
        $threshold = app(ProductManagement::class)->lowStockThreshold();

        return [
            'id' => $this->id,
            'name' => $this->getTranslations('name'),
            'category' => ['id' => $this->category->id, 'name' => $this->category->getTranslations('name'), 'is_active' => $this->category->is_active],
            'image' => $this->image ? ['id' => $this->image->id, 'url' => app(MediaService::class)->url($this->image)] : null,
            'is_active' => $this->is_active,
            'status' => $this->is_active ? 'active' : 'inactive',
            'visible' => $this->is_active && $this->category->is_active && (int) $this->active_variants_count > 0,
            'sort' => (int) $this->getAttribute('sort'),
            'variants_count' => (int) $this->variants_count,
            'active_variants_count' => (int) $this->active_variants_count,
            'price_from' => $this->money($this->price_min ?? $this->base_price),
            'stock_total' => (int) $this->active_stock,
            'in_stock' => (int) $this->active_stock > 0,
            'low_stock' => $threshold === null || $this->active_stock_min === null ? null : (int) $this->active_stock_min <= $threshold,
            'created_at' => $this->dateTime($this->created_at),
            'updated_at' => $this->dateTime($this->updated_at),
        ];
    }
}
