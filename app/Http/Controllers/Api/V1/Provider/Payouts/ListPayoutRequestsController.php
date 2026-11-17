<?php

namespace App\Http\Controllers\Api\V1\Provider\Payouts;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Provider\Payouts\ListPayoutRequestsRequest;
use App\Http\Resources\Api\V1\Provider\Payouts\ProviderPayoutRequestResource;
use App\Services\Payouts\PayoutRequests;
use App\Services\Providers\ProviderOnboarding;
use App\Support\Pagination;
use App\Traits\ApiResponse;

/**
 * Her payout requests, newest first.
 */
class ListPayoutRequestsController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly ProviderOnboarding $providers,
        private readonly PayoutRequests $requests,
    ) {}

    public function __invoke(ListPayoutRequestsRequest $request)
    {
        $page = $this->requests->list($this->providers->providerOf($request->user()), $request->validated('filter.status'), Pagination::perPage($request));

        return $this->success(ProviderPayoutRequestResource::collection($page));
    }
}
