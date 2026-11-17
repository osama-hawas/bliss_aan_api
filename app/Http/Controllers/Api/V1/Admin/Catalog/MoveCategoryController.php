<?php

namespace App\Http\Controllers\Api\V1\Admin\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Catalog\MoveCatalogItemRequest;
use App\Http\Resources\Api\V1\Admin\Catalog\AdminCategoryResource;
use App\Services\Catalog\Admin\CatalogCategories;
use App\Traits\ApiResponse;

/**
 * `catalog.manage` (D182). Swaps the order with the neighbour (409 at the edge).
 */
class MoveCategoryController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly CatalogCategories $catalog) {}

    public function __invoke(MoveCatalogItemRequest $request, int $category)
    {
        return $this->success(new AdminCategoryResource($this->catalog->move($category, $request->validated('direction'), $request->user())), __('catalog.messages.moved'));
    }
}
