<?php

namespace App\Http\Resources\Api\V1\Shared\Catalog;

use App\Http\Resources\Concerns\PublicMediaUrl;
use App\Models\Banner;
use App\Models\Provider;
use App\Models\ProviderService;
use App\Models\Service;
use App\Models\ServiceCategory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Banner
 */
class BannerResource extends JsonResource
{
    use PublicMediaUrl;

    /**
     * Destinations the app can open; anything else is returned as null.
     */
    private const DESTINATIONS = [
        Provider::class => 'provider',
        ProviderService::class => 'provider_service',
        ServiceCategory::class => 'category',
        Service::class => 'service',
    ];

    public function toArray(Request $request): array
    {
        $type = self::DESTINATIONS[$this->destination_type] ?? null;

        return [
            'id' => $this->id,
            'title' => $this->title,
            'image_url' => $this->publicUrl($this->image),
            'destination' => $type && $this->destination_id ? ['type' => $type, 'id' => $this->destination_id] : null,
        ];
    }
}
