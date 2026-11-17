<?php

namespace App\Http\Controllers\Api\V1\Admin\Store;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Store\ListStoreReturnsRequest;
use App\Http\Resources\Api\V1\Admin\Store\AdminStoreReturnRowResource;
use App\Services\Store\Returns\StoreReturnQueries;
use App\Support\Pagination;
use App\Traits\ApiResponse;

/**
 * Store returns for staff (orders.view, S23 §6.1).
 */
class ListStoreReturnsController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly StoreReturnQueries $queries) {}

    public function __invoke(ListStoreReturnsRequest $request)
    {
        $page = $this->queries->forAdmin($request->validated('q'), (array) $request->validated('filter', []), $request->validated('sort'), Pagination::perPage($request));

        return $this->success(AdminStoreReturnRowResource::collection($page));
    }
}
