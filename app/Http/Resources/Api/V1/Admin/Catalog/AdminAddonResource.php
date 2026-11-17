<?php

namespace App\Http\Resources\Api\V1\Admin\Catalog;

use App\Http\Resources\Concerns\FormatsApiValues;
use App\Models\Addon;
use App\Models\Service;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A catalog add-on. No price: providers price it (Slice 3). `providers_count` = active `provider_addons` rows.
 *
 * @mixin Addon
 */
class AdminAddonResource extends JsonResource
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
            'services' => $this->services->map(fn (Service $s) => ['id' => $s->id, 'name' => $this->both($s, 'name'), 'is_active' => (bool) $s->is_active])->values(),
            'services_count' => (int) $this->services_count,
            'providers_count' => (int) $this->providers_count,
            'created_at' => $this->dateTime($this->created_at),
            'updated_at' => $this->dateTime($this->updated_at),
        ];
    }
}
