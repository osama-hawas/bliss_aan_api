<?php

namespace App\Http\Controllers\Api\V1\Admin\Finance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Finance\FinancePeriodRequest;
use App\Http\Resources\Api\V1\Admin\Finance\FinanceOverviewResource;
use App\Services\Finance\FinanceOverview;
use App\Services\Finance\FinancePeriod;
use App\Traits\ApiResponse;

/**
 * Finance overview for a Riyadh-day period (finance.view, D294).
 */
class FinanceOverviewController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly FinanceOverview $overview) {}

    public function __invoke(FinancePeriodRequest $request)
    {
        $period = FinancePeriod::make($request->validated('from'), $request->validated('to'), FinanceOverview::MAX_DAYS);

        return $this->success(new FinanceOverviewResource($this->overview->build($period)));
    }
}
