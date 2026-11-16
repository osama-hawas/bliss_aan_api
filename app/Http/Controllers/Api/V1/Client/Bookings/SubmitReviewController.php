<?php

namespace App\Http\Controllers\Api\V1\Client\Bookings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Client\Bookings\SubmitReviewRequest;
use App\Http\Resources\Api\V1\Client\Bookings\ClientBookingResource;
use App\Services\Bookings\BookingQueries;
use App\Services\Reviews\ReviewService;
use App\Traits\ApiResponse;

/**
 * The client reviews her completed booking (Req 25, D129). 201 with the booking.
 */
class SubmitReviewController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly BookingQueries $bookings,
        private readonly ReviewService $service,
    ) {}

    public function __invoke(SubmitReviewRequest $request, int $booking)
    {
        $this->service->submit($request->user(), $this->bookings->clientBooking($request->user(), $booking),
            (int) $request->validated('rating'), $request->validated('comment'), $request->validated('tags') ?? []);

        return $this->success(new ClientBookingResource($this->bookings->clientBooking($request->user(), $booking)), __('Thank you for your review.'), 201);
    }
}
