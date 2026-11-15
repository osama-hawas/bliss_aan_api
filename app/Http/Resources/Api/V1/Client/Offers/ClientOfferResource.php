<?php

namespace App\Http\Resources\Api\V1\Client\Offers;

use App\Http\Resources\Concerns\DescribesOffer;
use App\Http\Resources\Concerns\FormatsApiValues;
use App\Http\Resources\Concerns\PublicMediaUrl;
use App\Models\Offer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An offer on the client's own request, with the provider's public card (slice-5b-contract.md §4). Never other
 * providers' private data; superseded and withdrawn offers never reach this resource.
 *
 * @mixin Offer
 */
class ClientOfferResource extends JsonResource
{
    use DescribesOffer, FormatsApiValues, PublicMediaUrl;

    public function toArray(Request $request): array
    {
        $o = $this->resource;
        $p = $o->provider;

        return [
            ...$this->offerCore($o),
            'service_request_id' => $o->service_request_id,
            'provider' => [
                'id' => $p?->id,
                'display_name' => $p?->display_name,
                'avatar_url' => $this->publicUrl($p?->avatar),
                'is_verified' => (bool) $p?->is_verified,
                'rating_avg' => $p?->rating_avg !== null ? (float) $p->rating_avg : null,
                'rating_count' => (int) ($p?->rating_count ?? 0),
            ],
        ];
    }
}
