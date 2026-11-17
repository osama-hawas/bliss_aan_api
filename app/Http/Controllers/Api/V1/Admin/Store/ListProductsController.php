<?php

namespace App\Http\Controllers\Api\V1\Admin\Store;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Store\ListProductsRequest;
use App\Http\Resources\Api\V1\Admin\Store\AdminProductResource;
use App\Services\Store\Admin\ProductManagement;
use App\Support\Pagination;
use App\Traits\ApiResponse;

/**
 * `products.manage`: search by name or SKU; filter by category, status, stock, low stock (contract §5.2).
 */
class ListProductsController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ProductManagement $products) {}

    public function __invoke(ListProductsRequest $request)
    {
        $page = $this->products->list($request->filters(), $request->validated('sort'), Pagination::perPage($request));

        return $this->success(AdminProductResource::collection($page));
    }
}
