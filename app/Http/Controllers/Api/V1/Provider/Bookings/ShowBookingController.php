<?php

namespace App\Http\Controllers\Api\V1\Provider\Bookings;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Provider\Bookings\ProviderBookingResource;
use App\Services\Availability\AvailabilityBlocks;
use App\Services\Bookings\BookingQueries;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

class ShowBookingController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly AvailabilityBlocks $blocks, private readonly BookingQueries $bookings) {}

    public function __invoke(Request $request, int $booking)
    {
        $provider = $this->blocks->providerOf($request->user());

        return $this->success(new ProviderBookingResource($this->bookings->providerBooking($provider, $booking)));
    }
}
