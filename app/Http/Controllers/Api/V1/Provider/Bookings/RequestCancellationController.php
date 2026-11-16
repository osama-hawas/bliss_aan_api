<?php

namespace App\Http\Controllers\Api\V1\Provider\Bookings;

use App\Enums\CancellationRequesterRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Shared\Bookings\CancellationRequestRequest;
use App\Http\Resources\Api\V1\Provider\Bookings\ProviderBookingResource;
use App\Services\Bookings\BookingQueries;
use App\Services\Cancellations\BookingCancellations;
use App\Services\Providers\ProviderSetup;
use App\Traits\ApiResponse;

/**
 * The provider reports she cannot fulfil a confirmed booking (Req 24, D127). 201 with the booking.
 */
class RequestCancellationController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly ProviderSetup $setup,
        private readonly BookingQueries $bookings,
        private readonly BookingCancellations $service,
    ) {}

    public function __invoke(CancellationRequestRequest $request, int $booking)
    {
        $provider = $this->setup->activeProvider($request->user());
        $this->service->request($request->user(), CancellationRequesterRole::Provider, $this->bookings->providerBooking($provider, $booking), $request->reason(), $request->validated('reason_text'));

        return $this->success(new ProviderBookingResource($this->bookings->providerBooking($provider, $booking)), __('Cancellation request sent for review.'), 201);
    }
}
