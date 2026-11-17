<?php

namespace App\Http\Controllers\Api\V1\Admin\Store;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Store\ReasonRequest;
use App\Http\Resources\Api\V1\Admin\Store\AdminStoreReturnResource;
use App\Services\Store\Returns\StoreReturnProcessing;
use App\Services\Store\Returns\StoreReturnQueries;
use App\Traits\ApiResponse;

/**
 * S23: requested → rejected (orders.update_status), reason required and shown to the client; E57.
 */
class RejectStoreReturnController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly StoreReturnProcessing $processing, private readonly StoreReturnQueries $queries) {}

    public function __invoke(ReasonRequest $request, int $return)
    {
        $this->queries->adminReturn($return); // 404 first
        $this->processing->reject($return, $request->user(), $request->validated('reason'));

        return $this->success(new AdminStoreReturnResource($this->queries->adminReturn($return)));
    }
}
