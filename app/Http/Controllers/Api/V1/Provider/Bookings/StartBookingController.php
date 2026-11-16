<?php

namespace App\Http\Controllers\Api\V1\Provider\Bookings;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Provider\Bookings\ProviderBookingResource;
use App\Services\Bookings\BookingExecution;
use App\Services\Bookings\BookingQueries;
use App\Services\Providers\ProviderSetup;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

/**
 * The booked provider starts the service (D126). 200 with the booking.
 */
class StartBookingController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly ProviderSetup $setup,
        private readonly BookingQueries $bookings,
        private readonly BookingExecution $service,
    ) {}

    public function __invoke(Request $request, int $booking)
    {
        $provider = $this->setup->activeProvider($request->user());
        $this->service->start($request->user(), $this->bookings->providerBooking($provider, $booking));

        return $this->success(new ProviderBookingResource($this->bookings->providerBooking($provider, $booking)), __('Service started.'));
    }
}
