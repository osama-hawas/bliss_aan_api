<?php

namespace App\Http\Controllers\Api\V1\Provider\Payouts;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Provider\Payouts\EarningsSummaryResource;
use App\Services\Payouts\ProviderEarnings;
use App\Services\Providers\ProviderOnboarding;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

/**
 * Her earnings summary (slice-11-contract.md §5). 409 NOT_CONFIGURED while a payout setting is not specified.
 */
class ShowEarningsController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly ProviderOnboarding $providers,
        private readonly ProviderEarnings $earnings,
    ) {}

    public function __invoke(Request $request)
    {
        return $this->success(new EarningsSummaryResource($this->earnings->summary($this->providers->providerOf($request->user()))));
    }
}
