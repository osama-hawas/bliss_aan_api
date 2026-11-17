<?php

namespace App\Http\Controllers\Api\V1\Admin\Store;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Admin\Store\AdminProductDetailResource;
use App\Services\Store\Admin\ProductManagement;
use App\Traits\ApiResponse;

/**
 * `products.manage`: product, every variant with exact stock, latest history.
 */
class ShowProductController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ProductManagement $products) {}

    public function __invoke(int $product)
    {
        return $this->success(new AdminProductDetailResource($this->products->find($product)));
    }
}
