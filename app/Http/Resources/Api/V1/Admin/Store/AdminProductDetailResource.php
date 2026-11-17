<?php

namespace App\Http\Resources\Api\V1\Admin\Store;

use App\Http\Resources\Concerns\FormatsApiValues;
use App\Models\AuditLog;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Media\MediaService;
use App\Services\Store\Admin\ProductManagement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A product with all its variants (active and stopped) and its latest history (contract §5.2).
 *
 * @mixin Product
 */
class AdminProductDetailResource extends JsonResource
{
    use FormatsApiValues;

    public function toArray(Request $request): array
    {
        $products = app(ProductManagement::class);
        $threshold = $products->lowStockThreshold();
        $active = $this->variants->where('is_active', true);

        return [
            'id' => $this->id,
            'name' => $this->getTranslations('name'),
            'description' => $this->getTranslations('description') ?: null,
            'category' => ['id' => $this->category->id, 'name' => $this->category->getTranslations('name'), 'is_active' => $this->category->is_active],
            'image' => $this->image ? ['id' => $this->image->id, 'url' => app(MediaService::class)->url($this->image)] : null,
            'is_active' => $this->is_active,
            'status' => $this->is_active ? 'active' : 'inactive',
            'visible' => $this->is_active && $this->category->is_active && $active->isNotEmpty(),
            'sort' => (int) $this->getAttribute('sort'),
            'base_price' => $this->money($this->base_price),
            'stock_total' => (int) $active->sum('stock'),
            'low_stock_threshold' => $threshold,
            'variants' => $this->variants->map(fn (ProductVariant $variant) => [
                ...(new AdminProductVariantResource($variant))->toArray($request),
                'low_stock' => $threshold === null ? null : $variant->is_active && $variant->stock <= $threshold,
            ])->values(),
            'history' => $products->history($this->resource)->map(fn (AuditLog $log) => [
                'id' => $log->id,
                'action' => $log->action,
                'reason' => $log->reason,
                'old' => $log->old,
                'new' => $log->new,
                'actor' => $log->actor ? ['id' => $log->actor->id, 'name' => $log->actor->name] : null,
                'created_at' => $this->dateTime($log->created_at),
            ])->values(),
            'created_at' => $this->dateTime($this->created_at),
            'updated_at' => $this->dateTime($this->updated_at),
        ];
    }
}
