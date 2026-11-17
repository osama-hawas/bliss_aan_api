<?php

namespace App\Http\Controllers\Api\V1\Admin\Finance;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Admin\Finance\AdminLedgerAdjustmentResource;
use App\Http\Resources\Api\V1\Admin\Finance\AdminProviderBalanceResource;
use App\Models\LedgerAdjustment;
use App\Services\Finance\ProviderBalances;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

/**
 * One provider's balances + her latest adjustments (finance.view). Any provider row (even without entries).
 */
class ShowProviderBalanceController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ProviderBalances $balances) {}

    public function __invoke(Request $request, int $provider)
    {
        $row = $this->balances->one($provider);
        $adjustments = LedgerAdjustment::query()->with(['provider:id,display_name', 'booking:id,reference', 'requester:id,name', 'decider:id,name'])
            ->where('provider_id', $provider)->latest('id')->limit(10)->get();

        return $this->success([
            ...(new AdminProviderBalanceResource($row))->toArray($request),
            'recent_adjustments' => AdminLedgerAdjustmentResource::collection($adjustments)->toArray($request),
        ]);
    }
}
