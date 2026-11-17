<?php

namespace App\Http\Controllers\Api\V1\Admin\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Catalog\StopCatalogItemRequest;
use App\Http\Resources\Api\V1\Admin\Catalog\AdminPackageResource;
use App\Services\Catalog\Admin\CatalogPackages;
use App\Traits\ApiResponse;

/**
 * `catalog.manage` (D182). Hides it from clients with a reason (D180, D181); never deletes.
 */
class StopPackageController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly CatalogPackages $catalog) {}

    public function __invoke(StopCatalogItemRequest $request, int $package)
    {
        return $this->success(new AdminPackageResource($this->catalog->stop($package, $request->user(), $request->validated('reason'), $request->validated('internal_note'))), __('catalog.messages.package_stopped'));
    }
}
