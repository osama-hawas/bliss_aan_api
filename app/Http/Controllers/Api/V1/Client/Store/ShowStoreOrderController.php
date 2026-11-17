<?php

namespace App\Http\Controllers\Api\V1\Client\Store;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Client\Store\ClientStoreOrderResource;
use App\Services\Store\StoreOrderQueries;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

/**
 * One of her orders, any status (§6.4).
 */
class ShowStoreOrderController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly StoreOrderQueries $queries) {}

    public function __invoke(Request $request, int $order)
    {
        return $this->success(new ClientStoreOrderResource($this->queries->clientOrder($request->user(), $order)));
    }
}
