<?php

namespace App\Http\Controllers\Api\V1\Admin\Store;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Admin\Store\AdminPromoCodeResource;
use App\Services\Store\PromoCodeAdmin;
use App\Traits\ApiResponse;

/**
 * One promo code with its recent uses (products.manage).
 */
class ShowPromoCodeController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly PromoCodeAdmin $promos) {}

    public function __invoke(int $promoCode)
    {
        return $this->success(new AdminPromoCodeResource($this->promos->find($promoCode), withUses: true));
    }
}
