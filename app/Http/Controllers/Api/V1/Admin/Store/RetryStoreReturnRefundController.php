<?php

namespace App\Http\Controllers\Api\V1\Admin\Store;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Admin\Store\AdminStoreReturnResource;
use App\Services\Store\Returns\StoreReturnProcessing;
use App\Services\Store\Returns\StoreReturnQueries;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

/**
 * S23: Retries the refund of a received return whose refund failed or never started (orders.update_status + refunds.process).
 */
class RetryStoreReturnRefundController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly StoreReturnProcessing $processing, private readonly StoreReturnQueries $queries) {}

    public function __invoke(Request $request, int $return)
    {
        $this->queries->adminReturn($return); // 404 first
        $this->processing->refund($return, $request->user());

        return $this->success(new AdminStoreReturnResource($this->queries->adminReturn($return)));
    }
}
