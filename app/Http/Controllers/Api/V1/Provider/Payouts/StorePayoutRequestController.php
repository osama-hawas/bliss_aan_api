<?php

namespace App\Http\Controllers\Api\V1\Provider\Payouts;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Provider\Payouts\StorePayoutRequestRequest;
use App\Http\Resources\Api\V1\Provider\Payouts\ProviderPayoutRequestResource;
use App\Services\Payouts\PayoutRequests;
use App\Traits\ApiResponse;

/**
 * Requests a payout of part or all of her available balance (D216, D221). 201 when created, 200 on a replay.
 */
class StorePayoutRequestController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly PayoutRequests $requests) {}

    public function __invoke(StorePayoutRequestRequest $request)
    {
        [$payout, $isNew] = $this->requests->create($request->user(), $request->validated('amount'), $request->validated('idempotency_key'));

        return $this->success(new ProviderPayoutRequestResource($payout), __('payouts.request_created'), $isNew ? 201 : 200);
    }
}
