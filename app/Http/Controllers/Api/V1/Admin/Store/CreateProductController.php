<?php

namespace App\Http\Controllers\Api\V1\Admin\Store;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Store\ProductRequest;
use App\Http\Resources\Api\V1\Admin\Store\AdminProductDetailResource;
use App\Services\Store\Admin\ProductManagement;
use App\Traits\ApiResponse;

/**
 * `products.manage`: product with at least one variant; initial stock recorded (D274).
 */
class CreateProductController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ProductManagement $products) {}

    public function __invoke(ProductRequest $request)
    {
        $product = $this->products->create($request->fields(), $request->user());

        return $this->success(new AdminProductDetailResource($product), __('store.admin.messages.product_created'), 201);
    }
}
