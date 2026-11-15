<?php

namespace App\Http\Controllers\Api\V1\Provider\Offers;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Provider\Offers\StoreOfferRequest;
use App\Http\Resources\Api\V1\Provider\ServiceRequests\ProviderRequestResource;
use App\Services\Offers\OfferSender;
use App\Services\Providers\ProviderSetup;
use App\Services\ServiceRequests\ServiceRequestQueries;
use App\Traits\ApiResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Sends an offer on a request she received (slice-5b-contract.md §3). 201 when created, 200 on an idempotent replay;
 * answers the request detail with her offers.
 */
class StoreOfferController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly ProviderSetup $setup,
        private readonly ServiceRequestQueries $queries,
        private readonly OfferSender $sender,
    ) {}

    public function __invoke(StoreOfferRequest $request, int $serviceRequest)
    {
        $provider = $this->setup->activeProvider($request->user());
        $recipient = $this->queries->recipient($provider, $serviceRequest);
        Gate::authorize('sendOffer', $recipient->serviceRequest);
        [, $isNew] = $this->sender->send($provider, $recipient->serviceRequest, $request->validated('idempotency_key'), $request->body());

        return $this->success(
            (new ProviderRequestResource($this->queries->recipient($provider, $serviceRequest)))->detailed(),
            __('Offer sent.'),
            $isNew ? 201 : 200,
        );
    }
}
