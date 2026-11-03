<?php

namespace App\Http\Resources\Api\V1\Shared\Providers;

use App\Enums\FavoriteType;
use App\Http\Resources\Concerns\FormatsApiValues;
use App\Http\Resources\Concerns\PublicMediaUrl;
use App\Models\ProviderService;
use App\Services\Catalog\FavoriteLookup;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A service as one provider offers it. `price` is her starting price (custom, else guide price);
 * null when neither is set. The final amount comes in an offer.
 *
 * @mixin ProviderService
 */
class ProviderServiceResource extends JsonResource
{
    use FormatsApiValues, PublicMediaUrl;

    public function toArray(Request $request): array
    {
        $service = $this->service;

        return [
            'id' => $this->id,
            'service' => [
                'id' => $service->id,
                'name' => $service->name,
                'category' => ['id' => $service->category->id, 'name' => $service->category->name],
            ],
            'description' => $service->description,
            'image_url' => $this->publicUrl($service->image),
            'price' => $this->money($this->price()),
            'at_provider_location' => $this->at_provider_location,
            'at_event_location' => $this->at_event_location,
            'provider' => new ProviderSummaryResource($this->whenLoaded('provider')),
            'is_favorite' => FavoriteLookup::for($request)->has(FavoriteType::ProviderService, $this->id),
        ];
    }
}
