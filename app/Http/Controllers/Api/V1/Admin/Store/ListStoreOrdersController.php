<?php

namespace App\Http\Controllers\Api\V1\Admin\Store;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Store\ListStoreOrdersRequest;
use App\Http\Resources\Api\V1\Admin\Store\AdminStoreOrderRowResource;
use App\Services\Store\StoreOrderQueries;
use App\Support\Pagination;
use App\Traits\ApiResponse;

/**
 * Store orders for staff (orders.view).
 */
class ListStoreOrdersController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly StoreOrderQueries $queries) {}

    public function __invoke(ListStoreOrdersRequest $request)
    {
        $page = $this->queries->forAdmin($request->validated('q'), (array) $request->validated('filter', []), $request->validated('sort'), Pagination::perPage($request));

        return $this->success(AdminStoreOrderRowResource::collection($page));
    }
}
