<?php

namespace App\Http\Controllers\Api\V1\Admin\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Catalog\StopCatalogItemRequest;
use App\Http\Resources\Api\V1\Admin\Catalog\AdminServiceDetailResource;
use App\Services\Catalog\Admin\CatalogServices;
use App\Traits\ApiResponse;

/**
 * `catalog.manage` (D182). Soft-disable with a reason (D180, D181); never deletes.
 */
class StopCatalogServiceController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly CatalogServices $catalog) {}

    public function __invoke(StopCatalogItemRequest $request, int $service)
    {
        $row = $this->catalog->stop($service, $request->user(), $request->validated('reason'), $request->validated('internal_note'));

        return $this->success(new AdminServiceDetailResource($row), __('catalog.messages.service_stopped'));
    }
}
