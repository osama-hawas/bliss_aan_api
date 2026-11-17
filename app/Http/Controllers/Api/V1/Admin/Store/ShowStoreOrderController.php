<?php

namespace App\Http\Controllers\Api\V1\Admin\Store;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Admin\Store\AdminStoreOrderResource;
use App\Services\Store\StoreOrderQueries;
use App\Traits\ApiResponse;

/**
 * One store order for staff (orders.view).
 */
class ShowStoreOrderController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly StoreOrderQueries $queries) {}

    public function __invoke(int $order)
    {
        return $this->success(new AdminStoreOrderResource($this->queries->adminOrder($order)));
    }
}
