<?php

namespace App\Http\Controllers\Api\V1\Admin\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Admin\Catalog\AdminServiceDetailResource;
use App\Services\Catalog\Admin\CatalogServices;
use App\Traits\ApiResponse;

/**
 * `catalog.manage` (D182). One row, any status.
 */
class ShowCatalogServiceController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly CatalogServices $catalog) {}

    public function __invoke(int $service)
    {
        return $this->success(new AdminServiceDetailResource($this->catalog->find($service)));
    }
}
