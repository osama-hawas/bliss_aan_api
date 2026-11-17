<?php

namespace App\Http\Controllers\Api\V1\Admin\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Admin\Catalog\AdminCategoryResource;
use App\Services\Catalog\Admin\CatalogCategories;
use App\Traits\ApiResponse;

/**
 * `catalog.manage` (D182). One row, any status.
 */
class ShowCategoryController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly CatalogCategories $catalog) {}

    public function __invoke(int $category)
    {
        return $this->success(new AdminCategoryResource($this->catalog->find($category)));
    }
}
