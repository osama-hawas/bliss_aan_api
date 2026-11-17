<?php

namespace App\Http\Controllers\Api\V1\Admin\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Catalog\MoveCatalogItemRequest;
use App\Http\Resources\Api\V1\Admin\Catalog\AdminAddonResource;
use App\Services\Catalog\Admin\CatalogAddons;
use App\Traits\ApiResponse;

/**
 * `catalog.manage` (D182). Swaps the order with the neighbour (409 at the edge).
 */
class MoveAddonController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly CatalogAddons $catalog) {}

    public function __invoke(MoveCatalogItemRequest $request, int $addon)
    {
        return $this->success(new AdminAddonResource($this->catalog->move($addon, $request->validated('direction'), $request->user())), __('catalog.messages.moved'));
    }
}
