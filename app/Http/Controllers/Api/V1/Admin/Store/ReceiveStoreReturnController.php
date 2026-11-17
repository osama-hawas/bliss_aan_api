<?php

namespace App\Http\Controllers\Api\V1\Admin\Store;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Store\ReceiveStoreReturnRequest;
use App\Http\Resources\Api\V1\Admin\Store\AdminStoreReturnResource;
use App\Services\Store\Returns\StoreReturnProcessing;
use App\Services\Store\Returns\StoreReturnQueries;
use App\Traits\ApiResponse;

/**
 * S23: approved → received (orders.update_status + refunds.process): restock, then the Slice 10 refund (D345); E58, E59 once settled.
 */
class ReceiveStoreReturnController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly StoreReturnProcessing $processing, private readonly StoreReturnQueries $queries) {}

    public function __invoke(ReceiveStoreReturnRequest $request, int $return)
    {
        $this->queries->adminReturn($return); // 404 first
        $this->processing->receive($return, $request->user(), $request->boolean('restock', true), $request->validated('note'));

        return $this->success(new AdminStoreReturnResource($this->queries->adminReturn($return)));
    }
}
