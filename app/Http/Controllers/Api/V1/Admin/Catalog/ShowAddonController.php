<?php

namespace App\Http\Controllers\Api\V1\Admin\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Admin\Catalog\AdminAddonResource;
use App\Services\Catalog\Admin\CatalogAddons;
use App\Traits\ApiResponse;

/**
 * `catalog.manage` (D182). One row, any status.
 */
class ShowAddonController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly CatalogAddons $catalog) {}

    public function __invoke(int $addon)
    {
        return $this->success(new AdminAddonResource($this->catalog->find($addon)));
    }
}
