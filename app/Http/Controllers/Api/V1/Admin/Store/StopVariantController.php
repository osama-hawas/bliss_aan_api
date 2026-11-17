<?php

namespace App\Http\Controllers\Api\V1\Admin\Store;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Store\StopRequest;
use App\Http\Resources\Api\V1\Admin\Store\AdminProductDetailResource;
use App\Services\Store\Admin\ProductManagement;
use App\Traits\ApiResponse;

/**
 * `products.manage` (D276).
 */
class StopVariantController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ProductManagement $products) {}

    public function __invoke(StopRequest $request, int $variant)
    {
        $row = $this->products->setVariantActive($variant, false, $request->user(), $request->validated('reason'));

        return $this->success(new AdminProductDetailResource($row), __('store.admin.messages.variant_stopped'));
    }
}
