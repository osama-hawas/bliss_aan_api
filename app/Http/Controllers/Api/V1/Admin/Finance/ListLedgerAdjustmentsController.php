<?php

namespace App\Http\Controllers\Api\V1\Admin\Finance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Finance\ListLedgerAdjustmentsRequest;
use App\Http\Resources\Api\V1\Admin\Finance\AdminLedgerAdjustmentResource;
use App\Services\Finance\AdjustmentQueries;
use App\Support\Pagination;
use App\Traits\ApiResponse;

/**
 * Ledger adjustments (finance.view or finance.process).
 */
class ListLedgerAdjustmentsController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly AdjustmentQueries $queries) {}

    public function __invoke(ListLedgerAdjustmentsRequest $request)
    {
        return $this->success(AdminLedgerAdjustmentResource::collection(
            $this->queries->list($request->filters(), $request->validated('sort'), Pagination::perPage($request)),
        ));
    }
}
