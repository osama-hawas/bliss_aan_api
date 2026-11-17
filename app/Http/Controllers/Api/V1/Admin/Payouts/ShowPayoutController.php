<?php

namespace App\Http\Controllers\Api\V1\Admin\Payouts;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Admin\Payouts\AdminPayoutDetailResource;
use App\Services\Payouts\PayoutQueries;
use App\Traits\ApiResponse;

/**
 * Payout detail with history (finance.view or payouts.process).
 */
class ShowPayoutController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly PayoutQueries $queries) {}

    public function __invoke(int $payout)
    {
        return $this->success(new AdminPayoutDetailResource($this->queries->adminOne($payout)));
    }
}
