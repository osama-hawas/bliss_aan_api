<?php

namespace App\Http\Controllers\Api\V1\Admin\Payouts;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Payouts\MarkPayoutPaidRequest;
use App\Http\Resources\Api\V1\Admin\Payouts\AdminPayoutDetailResource;
use App\Services\Payouts\PayoutProcessing;
use App\Services\Payouts\PayoutQueries;
use App\Traits\ApiResponse;

/**
 * requested → paid with the bank transfer reference (payouts.process, D216).
 */
class MarkPayoutPaidController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly PayoutQueries $queries,
        private readonly PayoutProcessing $processing,
    ) {}

    public function __invoke(MarkPayoutPaidRequest $request, int $payout)
    {
        $this->processing->markPaid($request->user(), $this->queries->adminOne($payout), $request->validated('transfer_reference'), $request->validated('internal_note'));

        return $this->success(new AdminPayoutDetailResource($this->queries->adminOne($payout)), __('payouts.marked_paid'));
    }
}
