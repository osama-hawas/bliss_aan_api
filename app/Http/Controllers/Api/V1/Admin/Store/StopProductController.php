<?php

namespace App\Http\Controllers\Api\V1\Admin\Store;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Store\StopRequest;
use App\Http\Resources\Api\V1\Admin\Store\AdminProductDetailResource;
use App\Services\Store\Admin\ProductManagement;
use App\Traits\ApiResponse;

/**
 * `products.manage` (D276): hidden from the apps; carts flag it, orders keep their snapshot.
 */
class StopProductController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ProductManagement $products) {}

    public function __invoke(StopRequest $request, int $product)
    {
        $row = $this->products->setActive($product, false, $request->user(), $request->validated('reason'), $request->validated('internal_note'));

        return $this->success(new AdminProductDetailResource($row), __('store.admin.messages.product_stopped'));
    }
}
