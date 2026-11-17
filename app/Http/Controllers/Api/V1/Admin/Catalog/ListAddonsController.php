<?php

namespace App\Http\Controllers\Api\V1\Admin\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Catalog\CatalogListRequest;
use App\Http\Resources\Api\V1\Admin\Catalog\AdminAddonResource;
use App\Services\Catalog\Admin\CatalogAddons;
use App\Support\Pagination;
use App\Traits\ApiResponse;

/**
 * `catalog.manage` (D182). Paginated list (contract §4).
 */
class ListAddonsController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly CatalogAddons $catalog) {}

    public function __invoke(CatalogListRequest $request)
    {
        return $this->success(AdminAddonResource::collection($this->catalog->list($request->filters(), $request->validated('sort'), Pagination::perPage($request))));
    }
}
