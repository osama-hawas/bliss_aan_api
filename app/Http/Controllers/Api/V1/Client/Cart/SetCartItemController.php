<?php

namespace App\Http\Controllers\Api\V1\Client\Cart;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Client\Cart\SetCartItemRequest;
use App\Http\Resources\Api\V1\Client\Cart\CartResource;
use App\Services\Store\CartService;
use App\Traits\ApiResponse;

/**
 * Sets one variant's quantity (adds the line when missing); checked against current stock, nothing reserved.
 */
class SetCartItemController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly CartService $carts) {}

    public function __invoke(SetCartItemRequest $request, int $variant)
    {
        $cart = $this->carts->setQuantity($request->user(), $variant, (int) $request->validated('quantity'));

        return $this->success(new CartResource($cart));
    }
}
