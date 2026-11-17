<?php

namespace App\Http\Controllers\Api\V1\Admin\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Catalog\MoveCatalogItemRequest;
use App\Http\Resources\Api\V1\Admin\Catalog\AdminPackageResource;
use App\Services\Catalog\Admin\CatalogPackages;
use App\Traits\ApiResponse;

/**
 * `catalog.manage` (D182). Swaps the order with the neighbour (409 at the edge).
 */
class MovePackageController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly CatalogPackages $catalog) {}

    public function __invoke(MoveCatalogItemRequest $request, int $package)
    {
        return $this->success(new AdminPackageResource($this->catalog->move($package, $request->validated('direction'), $request->user())), __('catalog.messages.moved'));
    }
}
