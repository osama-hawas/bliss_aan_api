<?php

namespace App\Http\Controllers\Api\V1\Admin\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Catalog\StopCatalogItemRequest;
use App\Http\Resources\Api\V1\Admin\Catalog\AdminAddonResource;
use App\Services\Catalog\Admin\CatalogAddons;
use App\Traits\ApiResponse;

/**
 * `catalog.manage` (D182). Soft-disable with a reason (D180, D181); never deletes.
 */
class StopAddonController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly CatalogAddons $catalog) {}

    public function __invoke(StopCatalogItemRequest $request, int $addon)
    {
        $row = $this->catalog->stop($addon, $request->user(), $request->validated('reason'), $request->validated('internal_note'));

        return $this->success(new AdminAddonResource($row), __('catalog.messages.addon_stopped'));
    }
}
