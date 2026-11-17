<?php

namespace App\Http\Controllers\Api\V1\Admin\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Catalog\ActivateCatalogItemRequest;
use App\Http\Resources\Api\V1\Admin\Catalog\AdminServiceDetailResource;
use App\Services\Catalog\Admin\CatalogServices;
use App\Traits\ApiResponse;

/**
 * `catalog.manage` (D182). Restores a stopped row; provider rows were kept (D180).
 */
class ActivateCatalogServiceController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly CatalogServices $catalog) {}

    public function __invoke(ActivateCatalogItemRequest $request, int $service)
    {
        return $this->success(new AdminServiceDetailResource($this->catalog->activate($service, $request->user(), $request->validated('reason'))), __('catalog.messages.service_activated'));
    }
}
