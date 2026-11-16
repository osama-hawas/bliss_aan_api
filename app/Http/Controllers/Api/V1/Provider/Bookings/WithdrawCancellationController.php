<?php

namespace App\Http\Controllers\Api\V1\Provider\Bookings;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Provider\Bookings\ProviderBookingResource;
use App\Models\CancellationRequest;
use App\Services\Bookings\BookingQueries;
use App\Services\Cancellations\BookingCancellations;
use App\Services\Providers\ProviderSetup;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

/**
 * The provider withdraws her own pending request.
 */
class WithdrawCancellationController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly ProviderSetup $setup,
        private readonly BookingQueries $bookings,
        private readonly BookingCancellations $service,
    ) {}

    public function __invoke(Request $request, int $cancellation)
    {
        $provider = $this->setup->activeProvider($request->user());
        $model = CancellationRequest::query()->where('requester_id', $request->user()->id)
            ->whereHas('booking', fn ($q) => $q->where('provider_id', $provider->id))->findOrFail($cancellation);
        $this->service->withdraw($request->user(), $model);

        return $this->success(new ProviderBookingResource($this->bookings->providerBooking($provider, $model->booking_id)), __('Cancellation request withdrawn.'));
    }
}
