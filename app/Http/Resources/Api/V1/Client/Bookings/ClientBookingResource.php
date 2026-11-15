<?php

namespace App\Http\Resources\Api\V1\Client\Bookings;

use App\Http\Resources\Concerns\DescribesBooking;
use App\Http\Resources\Concerns\DescribesOffer;
use App\Http\Resources\Concerns\FormatsApiValues;
use App\Http\Resources\Concerns\PublicMediaUrl;
use App\Models\Booking;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The client's own booking: the provider's public card, the accepted offer and her address snapshot.
 *
 * @mixin Booking
 */
class ClientBookingResource extends JsonResource
{
    use DescribesBooking, DescribesOffer, FormatsApiValues, PublicMediaUrl;

    public function toArray(Request $request): array
    {
        $b = $this->resource;
        $p = $b->provider;

        return [
            ...$this->bookingCore($b),
            'address' => $b->address,
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
