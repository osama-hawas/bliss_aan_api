<?php

namespace App\Http\Controllers\Api\V1\Admin\Finance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Finance\ListProviderBalancesRequest;
use App\Http\Resources\Api\V1\Admin\Finance\AdminProviderBalanceResource;
use App\Services\Finance\ProviderBalances;
use App\Support\Pagination;
use App\Traits\ApiResponse;

/**
 * Provider balances across providers (finance.view, D295).
 */
class ListProviderBalancesController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ProviderBalances $balances) {}

    public function __invoke(ListProviderBalancesRequest $request)
    {
        return $this->success(AdminProviderBalanceResource::collection(
            $this->balances->list($request->filters(), $request->validated('sort'), Pagination::perPage($request)),
        ));
    }
}
