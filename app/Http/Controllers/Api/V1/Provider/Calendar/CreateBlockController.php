<?php

namespace App\Http\Controllers\Api\V1\Provider\Calendar;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Provider\Calendar\CreateBlockRequest;
use App\Http\Resources\Api\V1\Provider\Calendar\BlockResource;
use App\Http\Resources\Api\V1\Provider\Calendar\CalendarRequestResource;
use App\Services\Availability\AvailabilityBlocks;
use App\Traits\ApiResponse;

/**
 * Blocks a whole day or a time range (§5.5). Returns the requests that fall in it so the app can warn her (D89).
 */
class CreateBlockController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly AvailabilityBlocks $blocks) {}

    public function __invoke(CreateBlockRequest $request)
    {
        [$startsAt, $endsAt] = $request->period();
        [$block, $conflicting] = $this->blocks->create($request->user(), $startsAt, $endsAt, $request->reason());

        return $this->success([
            ...(new BlockResource($block))->resolve($request),
            'conflicting_requests' => CalendarRequestResource::collection($conflicting)->resolve($request),
        ], status: 201);
    }
}
