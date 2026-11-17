<?php

namespace App\Http\Controllers\Api\V1\Admin\Payouts;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Payouts\RejectPayoutRequest;
use App\Http\Resources\Api\V1\Admin\Payouts\AdminPayoutDetailResource;
use App\Services\Payouts\PayoutProcessing;
use App\Services\Payouts\PayoutQueries;
use App\Traits\ApiResponse;

/**
 * requested → rejected with a reason; the amount returns to her balance (payouts.process, D216).
 */
class RejectPayoutController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly PayoutQueries $queries,
        private readonly PayoutProcessing $processing,
    ) {}

    public function __invoke(RejectPayoutRequest $request, int $payout)
    {
        $this->processing->reject($request->user(), $this->queries->adminOne($payout), $request->validated('reason'), $request->validated('internal_note'));

        return $this->success(new AdminPayoutDetailResource($this->queries->adminOne($payout)), __('payouts.rejected'));
    }
}
