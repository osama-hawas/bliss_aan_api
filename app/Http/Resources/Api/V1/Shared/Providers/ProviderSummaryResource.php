<?php

namespace App\Http\Resources\Api\V1\Shared\Providers;

use App\Http\Resources\Api\V1\Shared\Cities\CityResource;
use App\Http\Resources\Concerns\PublicMediaUrl;
use App\Models\Provider;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The provider behind a service (service details, favorite services).
 *
 * @mixin Provider
 */
class ProviderSummaryResource extends JsonResource
{
    use PublicMediaUrl;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'display_name' => $this->display_name,
            'city' => $this->city ? new CityResource($this->city) : null,
            'is_verified' => $this->is_verified,
            'rating' => ['average' => $this->rating_avg, 'count' => $this->rating_count],
            'avatar_url' => $this->publicUrl($this->avatar),
        ];
    }
}
