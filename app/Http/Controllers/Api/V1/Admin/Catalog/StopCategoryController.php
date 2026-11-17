<?php

namespace App\Http\Controllers\Api\V1\Admin\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Catalog\StopCatalogItemRequest;
use App\Http\Resources\Api\V1\Admin\Catalog\AdminCategoryResource;
use App\Services\Catalog\Admin\CatalogCategories;
use App\Traits\ApiResponse;

/**
 * `catalog.manage` (D182). Soft-disable with a reason (D180, D181); never deletes.
 */
class StopCategoryController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly CatalogCategories $catalog) {}

    public function __invoke(StopCatalogItemRequest $request, int $category)
    {
        $row = $this->catalog->stop($category, $request->user(), $request->validated('reason'), $request->validated('internal_note'));

        return $this->success(new AdminCategoryResource($row), __('catalog.messages.category_stopped'));
    }
}
