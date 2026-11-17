<?php

namespace App\Http\Resources\Api\V1\Provider\Bookings;

use App\Enums\BookingStatus;
use App\Enums\ExecutionPlace;
use App\Http\Resources\Concerns\DescribesBooking;
use App\Http\Resources\Concerns\DescribesBookingExecution;
use App\Http\Resources\Concerns\DescribesOffer;
use App\Http\Resources\Concerns\DescribesTeamMember;
use App\Http\Resources\Concerns\FormatsApiValues;
use App\Models\Booking;
use App\Models\ProviderTeamMember;
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
    use DescribesBooking, DescribesBookingExecution, DescribesOffer, DescribesTeamMember, FormatsApiValues;

    public function toArray(Request $request): array
    {
        $b = $this->resource;

        $data = [
            ...$this->bookingCore($b),
            ...$this->bookingExecution($b, $request->user(), true),
            'address' => $b->execution_place === ExecutionPlace::EventLocation ? $b->address : null,
            'client' => ['first_name' => $this->firstName($b->client)],
        ];

        // Slice 13 (S13): her team on this booking, provider only (D230); editable while confirmed / in progress (D232).
        $data['team'] = $b->team->map(fn (ProviderTeamMember $m) => $this->teamMemberItem($m))->values()->all();
        $data['actions']['can_assign_team'] = in_array($b->status, BookingStatus::busy(), true);

        return $data;
    }
}
