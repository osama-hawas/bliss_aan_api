<?php

namespace App\Http\Controllers\Api\V1\Admin\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Catalog\ServiceImpactRequest;
use App\Services\Catalog\Admin\CatalogServices;
use App\Traits\ApiResponse;

/**
 * `catalog.manage` (D182). Guide-price preview (with `guide_price`) or stop preview (§4.4).
 */
class CatalogServiceImpactController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly CatalogServices $catalog) {}

    public function __invoke(ServiceImpactRequest $request, int $service)
    {
        return $this->success($this->catalog->impact($service, $request->withPrice(), $request->proposed()));
    }
}
