<?php

namespace App\Http\Controllers\Api\V1\Admin\Finance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Finance\StoreLedgerAdjustmentRequest;
use App\Services\Finance\AdjustmentQueries;
use App\Services\Finance\LedgerAdjustments;
use App\Traits\ApiResponse;

/**
 * Request an adjustment (finance.process, D287). `201` new / `200` replay of the same Idempotency-Key.
 */
class CreateLedgerAdjustmentController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly LedgerAdjustments $service,
        private readonly AdjustmentQueries $queries,
    ) {}

    public function __invoke(StoreLedgerAdjustmentRequest $request)
    {
        [$adjustment, $created] = $this->service->create($request->user(), $request->validated('idempotency_key'), $request->body());

        return $this->success(ShowLedgerAdjustmentController::detail($this->queries, $adjustment->id), __('finance.adjustment_created'), $created ? 201 : 200);
    }
}
