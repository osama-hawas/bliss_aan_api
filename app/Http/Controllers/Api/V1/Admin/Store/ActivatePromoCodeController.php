<?php

namespace App\Http\Controllers\Api\V1\Admin\Store;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Store\ReasonRequest;
use App\Http\Resources\Api\V1\Admin\Store\AdminPromoCodeResource;
use App\Services\Store\PromoCodeAdmin;
use App\Traits\ApiResponse;

/**
 * Activate a stopped promo code (products.manage); reason audited.
 */
class ActivatePromoCodeController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly PromoCodeAdmin $promos) {}

    public function __invoke(ReasonRequest $request, int $promoCode)
    {
        return $this->success(new AdminPromoCodeResource($this->promos->setActive($request->user(), $this->promos->find($promoCode), true, $request->validated('reason'))));
    }
}
