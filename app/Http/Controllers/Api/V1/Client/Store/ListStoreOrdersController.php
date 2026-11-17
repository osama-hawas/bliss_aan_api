<?php

namespace App\Http\Controllers\Api\V1\Client\Store;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Client\Store\ListStoreOrdersRequest;
use App\Http\Resources\Api\V1\Client\Store\ClientStoreOrderResource;
use App\Services\Store\StoreOrderQueries;
use App\Support\Pagination;
use App\Traits\ApiResponse;

/**
 * Her placed orders, newest first (§6.4).
 */
class ListStoreOrdersController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly StoreOrderQueries $queries) {}

    public function __invoke(ListStoreOrdersRequest $request)
    {
        $page = $this->queries->forClient($request->user(), $request->validated('filter.status'), Pagination::perPage($request));
        $page->getCollection()->loadMissing('payment');

        return $this->success(ClientStoreOrderResource::collection($page));
    }
}
