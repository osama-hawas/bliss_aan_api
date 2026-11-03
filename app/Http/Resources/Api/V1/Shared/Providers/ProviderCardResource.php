<?php

namespace App\Http\Resources\Api\V1\Shared\Providers;

use App\Enums\FavoriteType;
use App\Http\Resources\Api\V1\Shared\Cities\CityResource;
use App\Http\Resources\Concerns\FormatsApiValues;
use App\Http\Resources\Concerns\PublicMediaUrl;
use App\Models\Provider;
use App\Models\ProviderService;
use App\Services\Catalog\FavoriteLookup;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Provider in lists (search, home, favorites). Public data only.
 * `services` must be loaded with offered() so categories reflect what she offers now.
 *
 * @mixin Provider
 */
class ProviderCardResource extends JsonResource
{
    use FormatsApiValues, PublicMediaUrl;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'display_name' => $this->display_name,
            'account_type' => $this->account_type->value,
            'account_type_label' => $this->account_type->label(),
            'city' => $this->city ? new CityResource($this->city) : null,
            'is_verified' => $this->is_verified,
            'rating' => ['average' => $this->rating_avg, 'count' => $this->rating_count],
            'response_time_minutes' => $this->response_time_minutes,
            // Lowest current price of the matching services; not an offer.
            'starting_price' => $this->when(array_key_exists('starting_price', $this->getAttributes()), fn () => $this->money($this->starting_price)),
            'categories' => $this->whenLoaded('services', fn () => $this->services
                ->map(fn (ProviderService $service) => $service->service->category)
                ->unique('id')->sortBy('sort')->values()
                ->map(fn ($category) => ['id' => $category->id, 'name' => $category->name])),
            'avatar_url' => $this->publicUrl($this->avatar),
            'cover_url' => $this->publicUrl($this->cover),
            'is_favorite' => FavoriteLookup::for($request)->has(FavoriteType::Provider, $this->id),
        ];
    }
}
