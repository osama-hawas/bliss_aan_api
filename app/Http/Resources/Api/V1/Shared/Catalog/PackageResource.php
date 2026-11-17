<?php

namespace App\Http\Resources\Api\V1\Shared\Catalog;

use App\Http\Resources\Concerns\FormatsApiValues;
use App\Http\Resources\Concerns\PublicMediaUrl;
use App\Models\EventType;
use App\Models\Package;
use App\Models\Service;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Package detail (`shared/packages/{id}`, Slice 24 §5). Each service has the `shared/services` shape (so the request form
 * can use it as is: `guest_count_mode`, active catalog `addons`) plus its `category`, `starting_price` ("starting from",
 * D355; null = no visible provider has a price) and `providers_count`. **No package price** (D350).
 *
 * @mixin Package
 */
class PackageResource extends JsonResource
{
    use FormatsApiValues, PublicMediaUrl;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'image_url' => $this->publicUrl($this->image),
            'event_types' => $this->eventTypes->map(fn (EventType $t) => ['id' => $t->id, 'name' => $t->name])->values(),
            'services' => $this->visibleServices->map(fn (Service $s) => [
                ...(new ServiceResource($s))->toArray($request),
                'category' => ['id' => $s->category_id, 'name' => $s->category?->name],
                'starting_price' => $this->money($s->getAttribute('starting_price')),
                'providers_count' => (int) $s->getAttribute('providers_count'),
            ])->values(),
        ];
    }
}
