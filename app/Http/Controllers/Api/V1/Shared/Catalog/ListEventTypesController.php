<?php

namespace App\Http\Controllers\Api\V1\Shared\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Shared\Catalog\EventTypeResource;
use App\Models\EventType;
use App\Traits\ApiResponse;

/**
 * Active event types (D19), for the request form's `event_type_id` (Slice 4). Public, not paginated.
 */
class ListEventTypesController extends Controller
{
    use ApiResponse;

    public function __invoke()
    {
        return $this->success(EventTypeResource::collection(
            EventType::query()->where('is_active', true)->orderBy('sort')->orderBy('id')->get(),
        ));
    }
}
