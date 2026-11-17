<?php

namespace App\Http\Controllers\Api\V1\Admin\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Admin\Catalog\AdminEventTypeResource;
use App\Services\Catalog\Admin\CatalogEventTypes;
use App\Traits\ApiResponse;

/**
 * `catalog.manage` (D182). One row, any status.
 */
class ShowEventTypeController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly CatalogEventTypes $catalog) {}

    public function __invoke(int $eventType)
    {
        return $this->success(new AdminEventTypeResource($this->catalog->find($eventType)));
    }
}
