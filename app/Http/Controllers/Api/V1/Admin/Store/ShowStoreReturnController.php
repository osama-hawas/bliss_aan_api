<?php

namespace App\Http\Controllers\Api\V1\Admin\Store;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Admin\Store\AdminStoreReturnResource;
use App\Services\Store\Returns\StoreReturnQueries;
use App\Traits\ApiResponse;

/**
 * One store return for staff (orders.view, S23 §6.2).
 */
class ShowStoreReturnController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly StoreReturnQueries $queries) {}

    public function __invoke(int $return)
    {
        return $this->success(new AdminStoreReturnResource($this->queries->adminReturn($return)));
    }
}
