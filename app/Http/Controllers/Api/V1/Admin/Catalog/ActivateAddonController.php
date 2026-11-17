<?php

namespace App\Http\Controllers\Api\V1\Admin\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Catalog\ActivateCatalogItemRequest;
use App\Http\Resources\Api\V1\Admin\Catalog\AdminAddonResource;
use App\Services\Catalog\Admin\CatalogAddons;
use App\Traits\ApiResponse;

/**
 * `catalog.manage` (D182). Restores a stopped row; provider rows were kept (D180).
 */
class ActivateAddonController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly CatalogAddons $catalog) {}

    public function __invoke(ActivateCatalogItemRequest $request, int $addon)
    {
        return $this->success(new AdminAddonResource($this->catalog->activate($addon, $request->user(), $request->validated('reason'))), __('catalog.messages.addon_activated'));
    }
}
