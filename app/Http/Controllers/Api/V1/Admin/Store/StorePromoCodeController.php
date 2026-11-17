<?php

namespace App\Http\Controllers\Api\V1\Admin\Store;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Store\StorePromoCodeRequest;
use App\Http\Resources\Api\V1\Admin\Store\AdminPromoCodeResource;
use App\Services\Store\PromoCodeAdmin;
use App\Traits\ApiResponse;

/**
 * New promo code (products.manage).
 */
class StorePromoCodeController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly PromoCodeAdmin $promos) {}

    public function __invoke(StorePromoCodeRequest $request)
    {
        return $this->success(new AdminPromoCodeResource($this->promos->create($request->user(), $request->validated())), null, 201);
    }
}
