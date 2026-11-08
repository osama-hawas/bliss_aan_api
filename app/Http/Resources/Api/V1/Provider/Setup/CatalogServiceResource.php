<?php

namespace App\Http\Resources\Api\V1\Provider\Setup;

use App\Http\Resources\Concerns\FormatsApiValues;
use App\Models\Service;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A catalog service a provider can offer, with the guide price (shown to providers, never in client lists).
 *
 * @mixin Service
 */
class CatalogServiceResource extends JsonResource
{
    use FormatsApiValues;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'category' => ['id' => $this->category->id, 'name' => $this->category->name],
            'guide_price' => $this->money($this->guide_price),
            'inclusions' => $this->inclusions->map(fn ($i) => ['id' => $i->id, 'text' => $i->text])->values(),
            'addons' => $this->addons->map(fn ($a) => ['id' => $a->id, 'name' => $a->name, 'description' => $a->description])->values(),
        ];
    }
}
