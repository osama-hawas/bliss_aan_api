<?php

namespace App\Http\Resources\Api\V1\Shared\Catalog;

use App\Http\Resources\Concerns\PublicMediaUrl;
use App\Models\EventType;
use App\Models\Package;
use App\Models\Service;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A package card (Home, `shared/packages`; Slice 24 §5). No price (D350). Only visible services are listed (D352).
 *
 * @mixin Package
 */
class PackageCardResource extends JsonResource
{
    use PublicMediaUrl;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'image_url' => $this->publicUrl($this->image),
            'services_count' => $this->visibleServices->count(),
            'services' => $this->visibleServices->map(fn (Service $s) => ['id' => $s->id, 'name' => $s->name])->values(),
            'event_types' => $this->eventTypes->map(fn (EventType $t) => ['id' => $t->id, 'name' => $t->name])->values(),
        ];
    }
}
