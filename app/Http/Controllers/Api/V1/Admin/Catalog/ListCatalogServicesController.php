<?php

namespace App\Http\Controllers\Api\V1\Admin\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Catalog\CatalogListRequest;
use App\Http\Resources\Api\V1\Admin\Catalog\AdminServiceResource;
use App\Services\Catalog\Admin\CatalogServices;
use App\Support\Pagination;
use App\Traits\ApiResponse;

/**
 * `catalog.manage` (D182). Paginated list (contract §4).
 */
class ListCatalogServicesController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly CatalogServices $catalog) {}

    public function __invoke(CatalogListRequest $request)
    {
        return $this->success(AdminServiceResource::collection($this->catalog->list($request->filters(), $request->validated('sort'), Pagination::perPage($request))));
    }
}
