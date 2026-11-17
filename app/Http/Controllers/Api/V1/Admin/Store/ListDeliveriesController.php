<?php

namespace App\Http\Controllers\Api\V1\Admin\Store;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Store\ListDeliveriesRequest;
use App\Http\Resources\Api\V1\Admin\Store\DeliveryResource;
use App\Services\Store\StoreOrderQueries;
use App\Support\Pagination;
use App\Traits\ApiResponse;

/**
 * Internal deliveries assigned to the caller (delivery.assigned).
 */
class ListDeliveriesController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly StoreOrderQueries $queries) {}

    public function __invoke(ListDeliveriesRequest $request)
    {
        return $this->success(DeliveryResource::collection($this->queries->deliveriesFor($request->user(), $request->validated('filter.status'), Pagination::perPage($request))));
    }
}
