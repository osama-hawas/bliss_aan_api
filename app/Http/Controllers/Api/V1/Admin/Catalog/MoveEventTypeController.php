<?php

namespace App\Http\Controllers\Api\V1\Admin\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Catalog\MoveCatalogItemRequest;
use App\Http\Resources\Api\V1\Admin\Catalog\AdminEventTypeResource;
use App\Services\Catalog\Admin\CatalogEventTypes;
use App\Traits\ApiResponse;

/**
 * `catalog.manage` (D182). Swaps the order with the neighbour (409 at the edge).
 */
class MoveEventTypeController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly CatalogEventTypes $catalog) {}

    public function __invoke(MoveCatalogItemRequest $request, int $eventType)
    {
        return $this->success(new AdminEventTypeResource($this->catalog->move($eventType, $request->validated('direction'), $request->user())), __('catalog.messages.moved'));
    }
}
