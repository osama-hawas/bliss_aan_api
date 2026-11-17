<?php

namespace App\Http\Controllers\Api\V1\Admin\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Catalog\SaveEventTypeRequest;
use App\Http\Resources\Api\V1\Admin\Catalog\AdminEventTypeResource;
use App\Services\Catalog\Admin\CatalogEventTypes;
use App\Traits\ApiResponse;

/**
 * `catalog.manage` (D182). Absent keys stay unchanged.
 */
class UpdateEventTypeController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly CatalogEventTypes $catalog) {}

    public function __invoke(SaveEventTypeRequest $request, int $eventType)
    {
        return $this->success(new AdminEventTypeResource($this->catalog->update($eventType, $request->payload(), $request->user())), __('catalog.messages.event_type_saved'));
    }
}
