<?php

namespace App\Http\Controllers\Api\V1\Admin\Reports;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Reports\ReportScopeRequest;
use App\Services\Reports\Indicators;
use App\Support\Pagination;
use App\Traits\ApiResponse;

/**
 * `GET admin/indicators/{group}/{key}/records`: the records behind one KPI item (D319). A group she may not see is a 404.
 */
class IndicatorRecordsController extends Controller
{
    use ApiResponse;

    public function __invoke(ReportScopeRequest $request, Indicators $indicators, string $group, string $key)
    {
        return $this->success($indicators->records($request->user(), $group, $key, $request->scope(), Pagination::perPage($request)));
    }
}
