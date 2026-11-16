<?php

namespace App\Http\Controllers\Api\V1\Client\Bookings;

use App\Enums\CancellationRequesterRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Shared\Bookings\CancellationRequestRequest;
use App\Http\Resources\Api\V1\Client\Bookings\ClientBookingResource;
use App\Services\Bookings\BookingQueries;
use App\Services\Cancellations\BookingCancellations;
use App\Traits\ApiResponse;

/**
 * The client asks to cancel her confirmed booking (Req 23, D127). 201 with the booking.
 */
class RequestCancellationController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly BookingQueries $bookings,
        private readonly BookingCancellations $service,
    ) {}

    public function __invoke(CancellationRequestRequest $request, int $booking)
    {
        $this->service->request($request->user(), CancellationRequesterRole::Client, $this->bookings->clientBooking($request->user(), $booking), $request->reason(), $request->validated('reason_text'));

        return $this->success(new ClientBookingResource($this->bookings->clientBooking($request->user(), $booking)), __('Cancellation request sent for review.'), 201);
    }
}
