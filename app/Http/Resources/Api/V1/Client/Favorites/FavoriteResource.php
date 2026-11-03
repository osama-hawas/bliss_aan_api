<?php

namespace App\Http\Resources\Api\V1\Client\Favorites;

use App\Enums\FavoriteType;
use App\Http\Resources\Api\V1\Shared\Providers\ProviderCardResource;
use App\Http\Resources\Api\V1\Shared\Providers\ProviderServiceResource;
use App\Http\Resources\Concerns\FormatsApiValues;
use App\Models\Favorite;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Favorite
 */
class FavoriteResource extends JsonResource
{
    use FormatsApiValues;

    public function toArray(Request $request): array
    {
        $type = $this->favoritable_type;

        return [
            'type' => $type->value,
            'id' => $this->favoritable_id,
            'provider' => $type === FavoriteType::Provider ? new ProviderCardResource($this->provider) : null,
            'provider_service' => $type === FavoriteType::ProviderService ? new ProviderServiceResource($this->providerService) : null,
            'created_at' => $this->dateTime($this->created_at),
        ];
    }
}
