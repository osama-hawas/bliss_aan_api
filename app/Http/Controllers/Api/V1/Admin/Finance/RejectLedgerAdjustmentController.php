<?php

namespace App\Http\Controllers\Api\V1\Admin\Finance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Finance\RejectLedgerAdjustmentRequest;
use App\Services\Finance\AdjustmentQueries;
use App\Services\Finance\LedgerAdjustments;
use App\Traits\ApiResponse;

/**
 * Reject a pending adjustment, or cancel one's own (finance.process, D289).
 */
class RejectLedgerAdjustmentController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly LedgerAdjustments $service,
        private readonly AdjustmentQueries $queries,
    ) {}

    public function __invoke(RejectLedgerAdjustmentRequest $request, int $adjustment)
    {
        $this->service->reject($request->user(), $adjustment, $request->validated('reason'));

        return $this->success(ShowLedgerAdjustmentController::detail($this->queries, $adjustment), __('finance.adjustment_rejected'));
    }
}
