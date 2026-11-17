<?php

namespace App\Http\Resources\Api\V1\Admin\Catalog;

use App\Http\Resources\Concerns\FormatsApiValues;
use App\Models\EventType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin EventType
 */
class AdminEventTypeResource extends JsonResource
{
    use DescribesCatalogRow, FormatsApiValues;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->both($this->resource, 'name'),
            ...$this->state((bool) $this->is_active),
            'sort' => (int) $this->sort,
            'requests_count' => (int) $this->requests_count,
            'created_at' => $this->dateTime($this->created_at),
            'updated_at' => $this->dateTime($this->updated_at),
        ];
    }
}
