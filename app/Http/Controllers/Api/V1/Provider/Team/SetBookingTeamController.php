<?php

namespace App\Http\Controllers\Api\V1\Provider\Team;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Provider\Team\SetBookingTeamRequest;
use App\Http\Resources\Api\V1\Provider\Bookings\ProviderBookingResource;
use App\Services\Bookings\BookingQueries;
use App\Services\Providers\ProviderSetup;
use App\Services\Team\BookingTeam;
use App\Traits\ApiResponse;

/**
 * Sets the team of one of her bookings (D230, D232, D233). 200 with the booking.
 */
class SetBookingTeamController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly ProviderSetup $setup,
        private readonly BookingQueries $bookings,
        private readonly BookingTeam $team,
    ) {}

    public function __invoke(SetBookingTeamRequest $request, int $booking)
    {
        $provider = $this->setup->activeProvider($request->user());
        $this->team->set($request->user(), $this->bookings->providerBooking($provider, $booking), $request->memberIds());

        return $this->success(new ProviderBookingResource($this->bookings->providerBooking($provider, $booking)), __('Booking team updated.'));
    }
}
