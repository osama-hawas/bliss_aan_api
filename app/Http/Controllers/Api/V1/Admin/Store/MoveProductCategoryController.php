<?php

namespace App\Http\Controllers\Api\V1\Admin\Store;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Store\MoveRequest;
use App\Http\Resources\Api\V1\Admin\Store\AdminProductCategoryResource;
use App\Services\Store\Admin\ProductCategoryManagement;
use App\Traits\ApiResponse;

/**
 * `products.manage` (D277): swaps the order with the neighbour.
 */
class MoveProductCategoryController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ProductCategoryManagement $categories) {}

    public function __invoke(MoveRequest $request, int $category)
    {
        return $this->success(new AdminProductCategoryResource($this->categories->move($category, $request->validated('direction'), $request->user())));
    }
}
