<?php

namespace App\Http\Controllers\Api\V1\Admin\Finance;

use App\Http\Controllers\Controller;
use App\Services\Finance\AdjustmentLimits;
use App\Support\Money;
use App\Traits\ApiResponse;

/**
 * The adjustment limits as set (read-only, D298; editing settings is Slice 20).
 */
class AdjustmentLimitsController extends Controller
{
    use ApiResponse;

    public function __invoke(AdjustmentLimits $limits)
    {
        $max = $limits->maxCents();
        $approval = $limits->approvalCents();

        return $this->success([
            'max_amount' => $max === null ? null : Money::format(Money::fromCents($max)),
            'approval_amount' => $approval === null ? null : Money::format(Money::fromCents($approval)),
            'configured' => $max !== null,
            'approval_always' => $approval === null,
        ]);
    }
}
