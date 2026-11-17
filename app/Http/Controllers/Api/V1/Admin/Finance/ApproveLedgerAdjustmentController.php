<?php

namespace App\Http\Controllers\Api\V1\Admin\Finance;

use App\Http\Controllers\Controller;
use App\Services\Finance\AdjustmentQueries;
use App\Services\Finance\LedgerAdjustments;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

/**
 * Approve and post (finance.process, four-eyes D289).
 */
class ApproveLedgerAdjustmentController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly LedgerAdjustments $service,
        private readonly AdjustmentQueries $queries,
    ) {}

    public function __invoke(Request $request, int $adjustment)
    {
        $this->service->approve($request->user(), $adjustment);

        return $this->success(ShowLedgerAdjustmentController::detail($this->queries, $adjustment), __('finance.adjustment_approved'));
    }
}
