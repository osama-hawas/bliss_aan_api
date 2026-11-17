<?php

namespace App\Http\Controllers\Api\V1\Provider\Payouts;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Provider\Payouts\ProviderPayoutRequestResource;
use App\Services\Payouts\PayoutRequests;
use App\Services\Providers\ProviderOnboarding;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

/**
 * One of her payout requests (another provider's id → 404).
 */
class ShowPayoutRequestController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly ProviderOnboarding $providers,
        private readonly PayoutRequests $requests,
    ) {}

    public function __invoke(Request $request, int $payoutRequest)
    {
        return $this->success(new ProviderPayoutRequestResource($this->requests->find($this->providers->providerOf($request->user()), $payoutRequest)));
    }
}
