<?php

namespace App\Http\Controllers\Api\V1\Admin\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Catalog\CatalogListRequest;
use App\Http\Resources\Api\V1\Admin\Catalog\AdminCategoryResource;
use App\Services\Catalog\Admin\CatalogCategories;
use App\Support\Pagination;
use App\Traits\ApiResponse;

/**
 * `catalog.manage` (D182). Paginated list (contract §4).
 */
class ListCategoriesController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly CatalogCategories $catalog) {}

    public function __invoke(CatalogListRequest $request)
    {
        return $this->success(AdminCategoryResource::collection($this->catalog->list($request->filters(), $request->validated('sort'), Pagination::perPage($request))));
    }
}
