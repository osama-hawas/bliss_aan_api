<?php

namespace App\Http\Controllers\Api\V1\Admin\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Catalog\StopCatalogItemRequest;
use App\Http\Resources\Api\V1\Admin\Catalog\AdminEventTypeResource;
use App\Services\Catalog\Admin\CatalogEventTypes;
use App\Traits\ApiResponse;

/**
 * `catalog.manage` (D182). Soft-disable with a reason (D180, D181); never deletes.
 */
class StopEventTypeController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly CatalogEventTypes $catalog) {}

    public function __invoke(StopCatalogItemRequest $request, int $eventType)
    {
        $row = $this->catalog->stop($eventType, $request->user(), $request->validated('reason'), $request->validated('internal_note'));

        return $this->success(new AdminEventTypeResource($row), __('catalog.messages.event_type_stopped'));
    }
}
