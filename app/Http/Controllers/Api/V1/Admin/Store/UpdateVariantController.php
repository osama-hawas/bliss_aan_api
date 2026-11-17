<?php

namespace App\Http\Controllers\Api\V1\Admin\Store;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Store\VariantRequest;
use App\Http\Resources\Api\V1\Admin\Store\AdminProductDetailResource;
use App\Services\Store\Admin\ProductManagement;
use App\Traits\ApiResponse;

/**
 * `products.manage` (D273, D281): SKU, options, price. Never stock.
 */
class UpdateVariantController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ProductManagement $products) {}

    public function __invoke(VariantRequest $request, int $variant)
    {
        return $this->success(new AdminProductDetailResource($this->products->updateVariant($variant, $request->validated(), $request->user())), __('store.admin.messages.variant_updated'));
    }
}
