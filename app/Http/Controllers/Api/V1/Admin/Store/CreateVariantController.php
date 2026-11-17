<?php

namespace App\Http\Controllers\Api\V1\Admin\Store;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Store\VariantRequest;
use App\Http\Resources\Api\V1\Admin\Store\AdminProductDetailResource;
use App\Services\Store\Admin\ProductManagement;
use App\Traits\ApiResponse;

/**
 * `products.manage` (D273): adds an option with its initial stock.
 */
class CreateVariantController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ProductManagement $products) {}

    public function __invoke(VariantRequest $request, int $product)
    {
        $row = $this->products->addVariant($product, $request->validated(), $request->user());

        return $this->success(new AdminProductDetailResource($row), __('store.admin.messages.variant_created'), 201);
    }
}
