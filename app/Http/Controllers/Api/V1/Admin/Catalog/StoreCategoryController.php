<?php

namespace App\Http\Controllers\Api\V1\Admin\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Catalog\SaveCategoryRequest;
use App\Http\Resources\Api\V1\Admin\Catalog\AdminCategoryResource;
use App\Services\Catalog\Admin\CatalogCategories;
use App\Traits\ApiResponse;

/**
 * `catalog.manage` (D182). Creates an active row at the end of its list.
 */
class StoreCategoryController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly CatalogCategories $catalog) {}

    public function __invoke(SaveCategoryRequest $request)
    {
        return $this->success(new AdminCategoryResource($this->catalog->create($request->payload(), $request->user())), __('catalog.messages.category_saved'), 201);
    }
}
