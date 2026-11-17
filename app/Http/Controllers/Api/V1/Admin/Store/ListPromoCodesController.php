<?php

namespace App\Http\Controllers\Api\V1\Admin\Store;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Store\ListPromoCodesRequest;
use App\Http\Resources\Api\V1\Admin\Store\AdminPromoCodeResource;
use App\Services\Store\PromoCodeAdmin;
use App\Support\Pagination;
use App\Traits\ApiResponse;

/**
 * Promo codes (products.manage).
 */
class ListPromoCodesController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly PromoCodeAdmin $promos) {}

    public function __invoke(ListPromoCodesRequest $request)
    {
        return $this->success(AdminPromoCodeResource::collection($this->promos->list($request->validated('q'), $request->validated('filter.state'), Pagination::perPage($request))));
    }
}
