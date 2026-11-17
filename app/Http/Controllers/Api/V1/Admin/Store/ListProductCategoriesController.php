<?php

namespace App\Http\Controllers\Api\V1\Admin\Store;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Store\ListProductCategoriesRequest;
use App\Http\Resources\Api\V1\Admin\Store\AdminProductCategoryResource;
use App\Services\Store\Admin\ProductCategoryManagement;
use App\Support\Pagination;
use App\Traits\ApiResponse;

/**
 * `products.manage`: store categories in admin order (contract §5.1).
 */
class ListProductCategoriesController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ProductCategoryManagement $categories) {}

    public function __invoke(ListProductCategoriesRequest $request)
    {
        return $this->success(AdminProductCategoryResource::collection($this->categories->list($request->filters(), Pagination::perPage($request))));
    }
}
