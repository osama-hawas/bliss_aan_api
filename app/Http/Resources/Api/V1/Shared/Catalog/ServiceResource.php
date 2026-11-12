<?php

namespace App\Http\Resources\Api\V1\Shared\Catalog;

use App\Http\Resources\Concerns\PublicMediaUrl;
use App\Models\Service;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A catalog service. No guide price: clients only ever see the chosen provider's price.
 *
 * @mixin Service
 */
class ServiceResource extends JsonResource
{
    use PublicMediaUrl;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'category_id' => $this->category_id,
            'name' => $this->name,
            'description' => $this->description,
            'image_url' => $this->publicUrl($this->image),
            'guest_count_mode' => $this->guest_count_mode?->value, // D54
            // Catalog add-ons a request may ask for (`addon_ids[]`); listed only where loaded (`shared/services`).
            'addons' => $this->whenLoaded('addons', fn () => $this->addons->map(fn ($addon) => [
                'id' => $addon->id,
                'name' => $addon->name,
                'description' => $addon->description,
            ])->values()),
        ];
    }
}
