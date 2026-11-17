<?php

namespace App\Http\Controllers\Api\V1\Admin\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Catalog\SavePackageRequest;
use App\Http\Resources\Api\V1\Admin\Catalog\AdminPackageResource;
use App\Services\Catalog\Admin\CatalogPackages;
use App\Traits\ApiResponse;

/**
 * `catalog.manage` (D182). Absent keys stay unchanged.
 */
class UpdatePackageController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly CatalogPackages $catalog) {}

    public function __invoke(SavePackageRequest $request, int $package)
    {
        return $this->success(new AdminPackageResource($this->catalog->update($package, $request->payload(), $request->user())), __('catalog.messages.package_saved'));
    }
}
