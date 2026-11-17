<?php

namespace App\Http\Controllers\Api\V1\Admin\Store;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Store\UpdatePromoCodeRequest;
use App\Http\Resources\Api\V1\Admin\Store\AdminPromoCodeResource;
use App\Services\Store\PromoCodeAdmin;
use App\Traits\ApiResponse;

/**
 * Edit a promo code (products.manage).
 */
class UpdatePromoCodeController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly PromoCodeAdmin $promos) {}

    public function __invoke(UpdatePromoCodeRequest $request, int $promoCode)
    {
        return $this->success(new AdminPromoCodeResource($this->promos->update($request->user(), $this->promos->find($promoCode), $request->validated())));
    }
}
