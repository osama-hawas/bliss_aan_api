<?php

namespace App\Http\Controllers\Api\V1\Provider\Payouts;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Provider\Payouts\PayoutAccountResource;
use App\Services\Payouts\PayoutAccounts;
use App\Services\Providers\ProviderOnboarding;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

/**
 * Her payout account (IBAN masked) or null.
 */
class ShowPayoutAccountController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly ProviderOnboarding $providers,
        private readonly PayoutAccounts $accounts,
    ) {}

    public function __invoke(Request $request)
    {
        $account = $this->accounts->of($this->providers->providerOf($request->user()));

        return $this->success($account ? new PayoutAccountResource($account) : null);
    }
}
