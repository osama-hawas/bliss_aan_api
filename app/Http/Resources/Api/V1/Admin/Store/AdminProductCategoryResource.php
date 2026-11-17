<?php

namespace App\Http\Resources\Api\V1\Admin\Store;

use App\Http\Resources\Concerns\FormatsApiValues;
use App\Models\ProductCategory;
use App\Services\Media\MediaService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A store category in the dashboard (contract §5.1).
 *
 * @mixin ProductCategory
 */
class AdminProductCategoryResource extends JsonResource
{
    use FormatsApiValues;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->getTranslations('name'),
            'image' => $this->image ? ['id' => $this->image->id, 'url' => app(MediaService::class)->url($this->image)] : null,
            'is_active' => $this->is_active,
            'status' => $this->is_active ? 'active' : 'inactive',
            'sort' => (int) $this->sort,
            'products_count' => (int) $this->products_count,
            'active_products_count' => (int) $this->active_products_count,
            'created_at' => $this->dateTime($this->created_at),
            'updated_at' => $this->dateTime($this->updated_at),
        ];
    }
}
