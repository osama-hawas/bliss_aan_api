<?php

namespace App\Http\Resources\Api\V1\Admin\Catalog;

use App\Http\Resources\Concerns\FormatsApiValues;
use App\Models\Service;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A catalog service row. `providers_count` = active `provider_services` rows.
 *
 * @mixin Service
 */
class AdminServiceResource extends JsonResource
{
    use DescribesCatalogRow, FormatsApiValues;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'category' => [
                'id' => $this->category->id,
                'name' => $this->both($this->category, 'name'),
                'is_active' => (bool) $this->category->is_active,
            ],
            'name' => $this->both($this->resource, 'name'),
            'description' => $this->both($this->resource, 'description'),
            'image' => $this->imageOf($this->image),
            'guide_price' => $this->money($this->guide_price),
            'guest_count_mode' => $this->guest_count_mode?->value,
            'guest_count_mode_label' => $this->guest_count_mode?->label(),
            ...$this->state((bool) $this->is_active),
            'is_visible_in_catalog' => (bool) $this->is_active && (bool) $this->category->is_active,
            'sort' => (int) $this->sort,
            'providers_count' => (int) $this->providers_count,
            'created_at' => $this->dateTime($this->created_at),
            'updated_at' => $this->dateTime($this->updated_at),
        ];
    }
}
