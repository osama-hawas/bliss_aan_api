<?php

namespace App\Http\Controllers\Api\V1\Admin\Store;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Store\ProductCategoryRequest;
use App\Http\Resources\Api\V1\Admin\Store\AdminProductCategoryResource;
use App\Services\Store\Admin\ProductCategoryManagement;
use App\Traits\ApiResponse;

/**
 * `products.manage`: new active category at the end of the list.
 */
class CreateProductCategoryController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ProductCategoryManagement $categories) {}

    public function __invoke(ProductCategoryRequest $request)
    {
        $category = $this->categories->create($request->data(), $request->user());

        return $this->success(new AdminProductCategoryResource($category), __('store.admin.messages.category_created'), 201);
    }
}
