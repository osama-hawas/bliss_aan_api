<?php

namespace App\Http\Controllers\Api\V1\Client\Bookings;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Client\Bookings\ClientBookingResource;
use App\Models\CancellationRequest;
use App\Services\Bookings\BookingQueries;
use App\Services\Cancellations\BookingCancellations;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

/**
 * The client withdraws her own pending request.
 */
class WithdrawCancellationController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly BookingQueries $bookings,
        private readonly BookingCancellations $service,
    ) {}

    public function __invoke(Request $request, int $cancellation)
    {
        $model = CancellationRequest::query()->where('requester_id', $request->user()->id)
            ->whereHas('booking', fn ($q) => $q->where('client_id', $request->user()->id))->findOrFail($cancellation);
        $this->service->withdraw($request->user(), $model);

        return $this->success(new ClientBookingResource($this->bookings->clientBooking($request->user(), $model->booking_id)), __('Cancellation request withdrawn.'));
    }
}
