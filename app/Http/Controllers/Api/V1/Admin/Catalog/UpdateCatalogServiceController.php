<?php

namespace App\Http\Controllers\Api\V1\Admin\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Catalog\SaveCatalogServiceRequest;
use App\Http\Resources\Api\V1\Admin\Catalog\AdminServiceDetailResource;
use App\Services\Catalog\Admin\CatalogServices;
use App\Traits\ApiResponse;

/**
 * `catalog.manage` (D182). Absent keys stay unchanged.
 */
class UpdateCatalogServiceController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly CatalogServices $catalog) {}

    public function __invoke(SaveCatalogServiceRequest $request, int $service)
    {
        return $this->success(new AdminServiceDetailResource($this->catalog->update($service, $request->payload(), $request->user())), __('catalog.messages.service_saved'));
    }
}
