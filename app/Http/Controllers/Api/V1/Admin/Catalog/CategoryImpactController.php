<?php

namespace App\Http\Controllers\Api\V1\Admin\Catalog;

use App\Http\Controllers\Controller;
use App\Services\Catalog\Admin\CatalogCategories;
use App\Services\Catalog\Admin\CatalogImpact;
use App\Traits\ApiResponse;

/**
 * `catalog.manage` (D182). Stop preview over the category's active services (§4.4).
 */
class CategoryImpactController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly CatalogCategories $catalog) {}

    public function __invoke(CatalogImpact $impact, int $category)
    {
        return $this->success($impact->forServices($this->catalog->activeServiceIds($category)));
    }
}
