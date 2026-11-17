<?php

namespace App\Http\Controllers\Api\V1\Admin\Finance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Finance\FinancePeriodRequest;
use App\Http\Resources\Concerns\FormatsCents;
use App\Services\Finance\FinancePeriod;
use App\Services\Finance\Reconciliation;
use App\Traits\ApiResponse;

/**
 * Daily totals per Riyadh day, for matching gateway and bank statements (finance.view, ≤ 92 days).
 */
class ReconciliationDailyController extends Controller
{
    use ApiResponse, FormatsCents;

    public function __invoke(FinancePeriodRequest $request, Reconciliation $reconciliation)
    {
        $period = FinancePeriod::make($request->validated('from'), $request->validated('to'), Reconciliation::DAILY_MAX_DAYS);

        return $this->success(array_map(fn (array $d) => [
            'date' => $d['date'],
            'payments_count' => $d['payments_count'],
            'captured' => $this->cents($d['captured_cents']),
            'refunded' => $this->cents($d['refunded_cents']),
            'net' => $this->cents($d['net_cents']),
            'earnings_net' => $this->cents($d['earnings_net_cents']),
            'commission' => $this->cents($d['commission_cents']),
            'adjustments_net' => $this->cents($d['adjustments_net_cents']),
            'payouts_paid' => $this->cents($d['payouts_paid_cents']),
        ], $reconciliation->daily($period)), null, 200, ['period' => ['from' => $period->from, 'to' => $period->to]]);
    }
}
