<?php

namespace App\Http\Controllers\Api\V1\Admin\Reports;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Reports\ReportScopeRequest;
use App\Services\Reports\Indicators;
use App\Traits\ApiResponse;

/**
 * `GET admin/indicators` (`dashboard.view`): KPI groups within the period and city (D316–D318, D329).
 */
class IndicatorsController extends Controller
{
    use ApiResponse;

    public function __invoke(ReportScopeRequest $request, Indicators $indicators)
    {
        $scope = $request->scope();

        return $this->success([
            'groups' => $indicators->groups($request->user(), $scope),
            'scope' => $scope->toArray(),
        ]);
    }
}
