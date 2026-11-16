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
 * The booked provider confirms completion herself (D125). Idempotent: a completed booking answers 200 unchanged.
 */
class CompleteBookingController extends Controller
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
        $this->service->complete($request->user(), $this->bookings->providerBooking($provider, $booking));

        return $this->success(new ProviderBookingResource($this->bookings->providerBooking($provider, $booking)), __('Service completed.'));
    }
}
