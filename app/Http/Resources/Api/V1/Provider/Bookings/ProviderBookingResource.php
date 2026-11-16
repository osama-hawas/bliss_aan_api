<?php

namespace App\Http\Resources\Api\V1\Provider\Bookings;

use App\Enums\ExecutionPlace;
use App\Http\Resources\Concerns\DescribesBooking;
use App\Http\Resources\Concerns\DescribesBookingExecution;
use App\Http\Resources\Concerns\DescribesOffer;
use App\Http\Resources\Concerns\FormatsApiValues;
use App\Models\Booking;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Her own booking. After booking she sees the exact address and pin at the event location (D56); the client stays
 * her first name only and the phone is never shown (D56, D77).
 *
 * @mixin Booking
 */
class ProviderBookingResource extends JsonResource
{
    use DescribesBooking, DescribesBookingExecution, DescribesOffer, FormatsApiValues;

    public function toArray(Request $request): array
    {
        $b = $this->resource;

        return [
            ...$this->bookingCore($b),
            ...$this->bookingExecution($b, $request->user(), true),
            'address' => $b->execution_place === ExecutionPlace::EventLocation ? $b->address : null,
            'client' => ['first_name' => $this->firstName($b->client)],
        ];
    }
}
