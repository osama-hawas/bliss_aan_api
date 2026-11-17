<?php

namespace App\Http\Controllers\Api\V1\Admin\Store;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Store\NoteRequest;
use App\Http\Resources\Api\V1\Admin\Store\AdminStoreReturnResource;
use App\Services\Store\Returns\StoreReturnProcessing;
use App\Services\Store\Returns\StoreReturnQueries;
use App\Traits\ApiResponse;

/**
 * S23: requested → approved (orders.update_status), optional internal note; E56.
 */
class ApproveStoreReturnController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly StoreReturnProcessing $processing, private readonly StoreReturnQueries $queries) {}

    public function __invoke(NoteRequest $request, int $return)
    {
        $this->queries->adminReturn($return); // 404 first
        $this->processing->approve($return, $request->user(), $request->validated('note'));

        return $this->success(new AdminStoreReturnResource($this->queries->adminReturn($return)));
    }
}
