<?php

namespace App\Http\Controllers\Api\V1\Admin\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Catalog\ActivateCatalogItemRequest;
use App\Http\Resources\Api\V1\Admin\Catalog\AdminCategoryResource;
use App\Services\Catalog\Admin\CatalogCategories;
use App\Traits\ApiResponse;

/**
 * `catalog.manage` (D182). Restores a stopped row; provider rows were kept (D180).
 */
class ActivateCategoryController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly CatalogCategories $catalog) {}

    public function __invoke(ActivateCatalogItemRequest $request, int $category)
    {
        return $this->success(new AdminCategoryResource($this->catalog->activate($category, $request->user(), $request->validated('reason'))), __('catalog.messages.category_activated'));
    }
}
