<?php

namespace App\Http\Controllers\Api\V1\Client\Bookings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Client\Bookings\AcceptOfferRequest;
use App\Http\Resources\Api\V1\Client\Bookings\ClientBookingResource;
use App\Services\Bookings\BookingQueries;
use App\Services\Bookings\OfferAcceptor;
use App\Services\ServiceRequests\ServiceRequestQueries;
use App\Traits\ApiResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Accepts an offer on her own request: the booking is confirmed at once (D113). 201 when created, 200 on an
 * idempotent replay.
 */
class AcceptOfferController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly ServiceRequestQueries $requests,
        private readonly BookingQueries $bookings,
        private readonly OfferAcceptor $acceptor,
    ) {}

    public function __invoke(AcceptOfferRequest $request, int $offer)
    {
        $model = $this->requests->clientOffer($request->user(), $offer);
        Gate::authorize('accept', $model->serviceRequest);
        [$booking, $isNew] = $this->acceptor->accept($request->user(), $model, $request->validated('idempotency_key'));

        return $this->success(
            new ClientBookingResource($this->bookings->clientBooking($request->user(), $booking->id)),
            __('Booking confirmed.'),
            $isNew ? 201 : 200,
        );
    }
}
