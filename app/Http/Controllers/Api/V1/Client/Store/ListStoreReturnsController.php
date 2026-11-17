<?php

namespace App\Http\Controllers\Api\V1\Client\Store;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Client\Store\ListStoreReturnsRequest;
use App\Http\Resources\Api\V1\Client\Store\ClientStoreReturnResource;
use App\Services\Store\Returns\StoreReturnQueries;
use App\Services\Store\StoreOrderQueries;
use App\Support\Pagination;
use App\Traits\ApiResponse;

/**
 * Her returns (S23, §5.2): all of them (`client/store-returns`) or one order's (`client/store-orders/{id}/returns`).
 */
class ListStoreReturnsController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly StoreReturnQueries $queries, private readonly StoreOrderQueries $orders) {}

    public function __invoke(ListStoreReturnsRequest $request)
    {
        $order = $request->route('order') === null ? null : (int) $request->route('order');
        if ($order !== null) {
            $this->orders->clientOrder($request->user(), $order); // foreign order → 404
        }
        $page = $this->queries->forClient($request->user(), $order, $request->validated('filter.status'), Pagination::perPage($request));

        return $this->success(ClientStoreReturnResource::collection($page));
    }
}
