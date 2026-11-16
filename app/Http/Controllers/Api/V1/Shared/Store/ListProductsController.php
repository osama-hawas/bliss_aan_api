<?php

namespace App\Http\Controllers\Api\V1\Shared\Store;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Shared\Store\ListProductsRequest;
use App\Http\Resources\Api\V1\Shared\Store\ProductResource;
use App\Services\Store\ProductCatalog;
use App\Support\Pagination;
use App\Traits\ApiResponse;

class ListProductsController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ProductCatalog $catalog) {}

    /**
     * Visible store products, paginated; `filter[category_id]`, `q`. Public (Slice 9).
     */
    public function __invoke(ListProductsRequest $request)
    {
        $page = $this->catalog->products($request->categoryId(), $request->q(), Pagination::perPage($request));

        return $this->success(ProductResource::collection($page));
    }
}
