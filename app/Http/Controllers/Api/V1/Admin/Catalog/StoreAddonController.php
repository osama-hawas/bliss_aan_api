<?php

namespace App\Http\Controllers\Api\V1\Admin\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Catalog\SaveAddonRequest;
use App\Http\Resources\Api\V1\Admin\Catalog\AdminAddonResource;
use App\Services\Catalog\Admin\CatalogAddons;
use App\Traits\ApiResponse;

/**
 * `catalog.manage` (D182). Creates an active row at the end of its list.
 */
class StoreAddonController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly CatalogAddons $catalog) {}

    public function __invoke(SaveAddonRequest $request)
    {
        return $this->success(new AdminAddonResource($this->catalog->create($request->payload(), $request->user())), __('catalog.messages.addon_saved'), 201);
    }
}
