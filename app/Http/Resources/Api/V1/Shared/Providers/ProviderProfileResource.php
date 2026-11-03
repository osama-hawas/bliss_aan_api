<?php

namespace App\Http\Resources\Api\V1\Shared\Providers;

use App\Http\Resources\Api\V1\Shared\Cities\CityResource;
use App\Models\PortfolioItem;
use App\Models\Provider;
use App\Services\Media\MediaService;
use Illuminate\Http\Request;

/**
 * Public provider profile. Load it through ProviderDirectory::profile().
 *
 * @mixin Provider
 */
class ProviderProfileResource extends ProviderCardResource
{
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'bio' => $this->bio,
            'started_year' => $this->started_year,
            'coverage_cities' => CityResource::collection($this->coverageCities->sortBy('sort')->values()),
            'services' => ProviderServiceResource::collection($this->services),
            'portfolio' => $this->portfolioItems->map(fn (PortfolioItem $item) => [
                'id' => $item->id,
                'caption' => $item->caption,
                'url' => app(MediaService::class)->url($item->media),
            ])->values(),
        ];
    }
}
