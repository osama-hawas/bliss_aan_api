<?php

namespace App\Http\Controllers\Api\V1\Admin\Payouts;

use App\Http\Controllers\Controller;
use App\Services\Payouts\PayoutProcessing;
use App\Services\Payouts\PayoutQueries;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

/**
 * The full IBAN of the request's snapshot (payouts.process only, audit-logged, D223).
 */
class ShowPayoutIbanController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly PayoutQueries $queries,
        private readonly PayoutProcessing $processing,
    ) {}

    public function __invoke(Request $request, int $payout)
    {
        return $this->success(['iban' => $this->processing->revealIban($request->user(), $this->queries->adminOne($payout))]);
    }
}
