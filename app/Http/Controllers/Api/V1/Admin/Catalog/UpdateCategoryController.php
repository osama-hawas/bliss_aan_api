<?php

namespace App\Http\Controllers\Api\V1\Admin\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Catalog\SaveCategoryRequest;
use App\Http\Resources\Api\V1\Admin\Catalog\AdminCategoryResource;
use App\Services\Catalog\Admin\CatalogCategories;
use App\Traits\ApiResponse;

/**
 * `catalog.manage` (D182). Absent keys stay unchanged.
 */
class UpdateCategoryController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly CatalogCategories $catalog) {}

    public function __invoke(SaveCategoryRequest $request, int $category)
    {
        return $this->success(new AdminCategoryResource($this->catalog->update($category, $request->payload(), $request->user())), __('catalog.messages.category_saved'));
    }
}
