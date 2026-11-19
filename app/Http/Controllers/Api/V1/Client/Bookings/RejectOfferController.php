<?php

namespace App\Http\Controllers\Api\V1\Client\Bookings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Client\Bookings\RejectOfferRequest;
use App\Http\Resources\Api\V1\Client\Offers\ClientOfferResource;
use App\Services\Offers\OfferRejecter;
use App\Services\ServiceRequests\ServiceRequestQueries;
use App\Traits\ApiResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Rejects one live offer on her own request (QA BUG-012). 409 INVALID_STATE when the offer is no longer live or the
 * request no longer takes offers.
 */
class RejectOfferController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly ServiceRequestQueries $requests,
        private readonly OfferRejecter $rejecter,
    ) {}

    public function __invoke(RejectOfferRequest $request, int $offer)
    {
        $model = $this->requests->clientOffer($request->user(), $offer);
        Gate::authorize('accept', $model->serviceRequest);
        $rejected = $this->rejecter->reject($request->user(), $model, $request->validated('reason'));

        return $this->success(
            new ClientOfferResource($this->requests->clientOffer($request->user(), $rejected->id)),
            __('Offer rejected.'),
        );
    }
}
