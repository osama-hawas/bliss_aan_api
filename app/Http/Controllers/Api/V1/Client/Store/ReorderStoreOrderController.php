<?php

namespace App\Http\Controllers\Api\V1\Client\Store;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Client\Cart\CartResource;
use App\Services\Store\ReorderService;
use App\Services\Store\StoreOrderQueries;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

/**
 * Reorder (§6.4, D211): her placed order's lines into the cart at current stock and prices.
 */
class ReorderStoreOrderController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ReorderService $reorder, private readonly StoreOrderQueries $queries) {}

    public function __invoke(Request $request, int $order)
    {
        [$cart, $skipped] = $this->reorder->reorder($request->user(), $this->queries->clientOrder($request->user(), $order));

        return $this->success(['cart' => (new CartResource($cart))->resolve($request), 'skipped' => $skipped]);
    }
}
