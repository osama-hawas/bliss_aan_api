<?php

namespace App\Http\Controllers\Api\V1\Admin\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Catalog\PackageListRequest;
use App\Http\Resources\Api\V1\Admin\Catalog\AdminPackageResource;
use App\Services\Catalog\Admin\CatalogPackages;
use App\Support\Pagination;
use App\Traits\ApiResponse;

/**
 * `catalog.manage` (D182). Paginated package list, any status (Slice 24 §4).
 */
class ListPackagesController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly CatalogPackages $catalog) {}

    public function __invoke(PackageListRequest $request)
    {
        return $this->success(AdminPackageResource::collection($this->catalog->list($request->filters(), $request->validated('sort'), Pagination::perPage($request))));
    }
}
