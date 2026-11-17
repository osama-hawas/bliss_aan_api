<?php

namespace App\Http\Controllers\Api\V1\Admin\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Catalog\ActivateCatalogItemRequest;
use App\Http\Resources\Api\V1\Admin\Catalog\AdminEventTypeResource;
use App\Services\Catalog\Admin\CatalogEventTypes;
use App\Traits\ApiResponse;

/**
 * `catalog.manage` (D182). Restores a stopped row; provider rows were kept (D180).
 */
class ActivateEventTypeController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly CatalogEventTypes $catalog) {}

    public function __invoke(ActivateCatalogItemRequest $request, int $eventType)
    {
        return $this->success(new AdminEventTypeResource($this->catalog->activate($eventType, $request->user(), $request->validated('reason'))), __('catalog.messages.event_type_activated'));
    }
}
