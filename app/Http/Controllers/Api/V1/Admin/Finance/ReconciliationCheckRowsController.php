<?php

namespace App\Http\Controllers\Api\V1\Admin\Finance;

use App\Enums\ErrorCode;
use App\Enums\ReconciliationCheck;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Admin\Finance\ReconciliationRowResource;
use App\Services\Finance\Reconciliation;
use App\Support\Pagination;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

/**
 * The findings of one check, oldest first (finance.view). Unknown key → 404.
 */
class ReconciliationCheckRowsController extends Controller
{
    use ApiResponse;

    public function __invoke(Request $request, Reconciliation $reconciliation, string $check)
    {
        $case = ReconciliationCheck::tryFrom($check) ?? throw new ApiException(ErrorCode::NotFound);

        return $this->success(ReconciliationRowResource::collection($reconciliation->rows($case, Pagination::perPage($request))));
    }
}
