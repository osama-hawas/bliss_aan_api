<?php

namespace App\Http\Controllers\Api\V1\Client\Bookings;

use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Client\Bookings\AcceptOfferRequest;
use App\Http\Resources\Api\V1\Client\Bookings\ClientBookingResource;
use App\Models\Booking;
use App\Services\Bookings\BookingQueries;
use App\Services\Bookings\OfferAcceptor;
use App\Services\Payments\PaymentSettings;
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
        private readonly PaymentSettings $payments,
    ) {}

    public function __invoke(AcceptOfferRequest $request, int $offer)
    {
        $model = $this->requests->clientOffer($request->user(), $offer);
        Gate::authorize('accept', $model->serviceRequest);
        // Slice 10 (D185): with the payment flag on, she pays first (POST client/offers/{id}/payments). A replay of an
        // acceptance made before the flag was switched on still returns its booking.
        if ($this->payments->bookingPaymentRequired()
            && ! Booking::query()->where('client_id', $request->user()->id)->where('idempotency_key', $request->validated('idempotency_key'))->exists()) {
            throw new ApiException(ErrorCode::PaymentRequired);
        }
        [$booking, $isNew] = $this->acceptor->accept($request->user(), $model, $request->validated('idempotency_key'));

        return $this->success(
            new ClientBookingResource($this->bookings->clientBooking($request->user(), $booking->id)),
            __('Booking confirmed.'),
            $isNew ? 201 : 200,
        );
    }
}
