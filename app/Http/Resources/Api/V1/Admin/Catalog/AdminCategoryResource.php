<?php

namespace App\Http\Resources\Api\V1\Admin\Catalog;

use App\Http\Resources\Concerns\FormatsApiValues;
use App\Models\ServiceCategory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ServiceCategory
 */
class AdminCategoryResource extends JsonResource
{
    use DescribesCatalogRow, FormatsApiValues;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->both($this->resource, 'name'),
            'description' => $this->both($this->resource, 'description'),
            'image' => $this->imageOf($this->image),
            ...$this->state((bool) $this->is_active),
            'sort' => (int) $this->sort,
            'services_count' => (int) $this->services_count,
            'active_services_count' => (int) $this->active_services_count,
            'created_at' => $this->dateTime($this->created_at),
            'updated_at' => $this->dateTime($this->updated_at),
        ];
    }
}
