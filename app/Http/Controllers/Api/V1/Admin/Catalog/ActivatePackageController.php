<?php

namespace App\Http\Controllers\Api\V1\Admin\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Catalog\ActivateCatalogItemRequest;
use App\Http\Resources\Api\V1\Admin\Catalog\AdminPackageResource;
use App\Services\Catalog\Admin\CatalogPackages;
use App\Traits\ApiResponse;

/**
 * `catalog.manage` (D182). Shows a stopped package again.
 */
class ActivatePackageController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly CatalogPackages $catalog) {}

    public function __invoke(ActivateCatalogItemRequest $request, int $package)
    {
        return $this->success(new AdminPackageResource($this->catalog->activate($package, $request->user(), $request->validated('reason'))), __('catalog.messages.package_activated'));
    }
}
