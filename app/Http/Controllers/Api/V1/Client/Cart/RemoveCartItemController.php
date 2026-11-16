<?php

namespace App\Http\Controllers\Api\V1\Client\Cart;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Client\Cart\CartResource;
use App\Services\Store\CartService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

/**
 * Removes one variant from her cart (idempotent).
 */
class RemoveCartItemController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly CartService $carts) {}

    public function __invoke(Request $request, int $variant)
    {
        return $this->success(new CartResource($this->carts->remove($request->user(), $variant)));
    }
}
