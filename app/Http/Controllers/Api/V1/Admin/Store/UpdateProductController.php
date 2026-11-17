<?php

namespace App\Http\Controllers\Api\V1\Admin\Store;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Store\ProductRequest;
use App\Http\Resources\Api\V1\Admin\Store\AdminProductDetailResource;
use App\Services\Store\Admin\ProductManagement;
use App\Traits\ApiResponse;

/**
 * `products.manage`: category, name, description, image, sort.
 */
class UpdateProductController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ProductManagement $products) {}

    public function __invoke(ProductRequest $request, int $product)
    {
        return $this->success(new AdminProductDetailResource($this->products->update($product, $request->data(), $request->user())), __('store.admin.messages.product_updated'));
    }
}
