<?php

namespace App\Http\Controllers\Api\V1\Admin\Store;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Store\ProductCategoryRequest;
use App\Http\Resources\Api\V1\Admin\Store\AdminProductCategoryResource;
use App\Services\Store\Admin\ProductCategoryManagement;
use App\Traits\ApiResponse;

/**
 * `products.manage`: name and image.
 */
class UpdateProductCategoryController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ProductCategoryManagement $categories) {}

    public function __invoke(ProductCategoryRequest $request, int $category)
    {
        return $this->success(new AdminProductCategoryResource($this->categories->update($category, $request->data(), $request->user())), __('store.admin.messages.category_updated'));
    }
}
