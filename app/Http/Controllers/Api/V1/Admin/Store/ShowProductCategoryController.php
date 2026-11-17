<?php

namespace App\Http\Controllers\Api\V1\Admin\Store;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Admin\Store\AdminProductCategoryResource;
use App\Services\Store\Admin\ProductCategoryManagement;
use App\Traits\ApiResponse;

/**
 * `products.manage`.
 */
class ShowProductCategoryController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ProductCategoryManagement $categories) {}

    public function __invoke(int $category)
    {
        return $this->success(new AdminProductCategoryResource($this->categories->find($category)));
    }
}
