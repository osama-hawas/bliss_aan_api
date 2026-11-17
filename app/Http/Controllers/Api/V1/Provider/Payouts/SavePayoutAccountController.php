<?php

namespace App\Http\Controllers\Api\V1\Provider\Payouts;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Provider\Payouts\SavePayoutAccountRequest;
use App\Http\Resources\Api\V1\Provider\Payouts\PayoutAccountResource;
use App\Services\Payouts\PayoutAccounts;
use App\Traits\ApiResponse;

/**
 * Creates or replaces her one payout account (D216). Open requests keep their snapshot.
 */
class SavePayoutAccountController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly PayoutAccounts $accounts) {}

    public function __invoke(SavePayoutAccountRequest $request)
    {
        $account = $this->accounts->save($request->user(), $request->validated('holder_name'), $request->validated('iban'));

        return $this->success(new PayoutAccountResource($account), __('payouts.account_saved'));
    }
}
