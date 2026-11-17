<?php

namespace App\Http\Controllers\Api\V1\Client\Store;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Client\Store\ClientStoreReturnResource;
use App\Services\Store\Returns\StoreReturnQueries;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

/**
 * One of her returns (S23, §5.2). Under an order path the return must belong to that order.
 */
class ShowStoreReturnController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly StoreReturnQueries $queries) {}

    public function __invoke(Request $request)
    {
        // `client/store-returns/{return}` or `client/store-orders/{order}/returns/{return}`.
        $orderId = $request->route('order') === null ? null : (int) $request->route('order');
        $returnId = (int) $request->route('return');

        return $this->success(new ClientStoreReturnResource($this->queries->clientReturn($request->user(), $returnId, $orderId)));
    }
}
