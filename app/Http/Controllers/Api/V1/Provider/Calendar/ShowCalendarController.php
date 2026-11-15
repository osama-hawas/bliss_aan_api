<?php

namespace App\Http\Controllers\Api\V1\Provider\Calendar;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Provider\Calendar\ShowCalendarRequest;
use App\Http\Resources\Api\V1\Provider\Calendar\BlockResource;
use App\Http\Resources\Api\V1\Provider\Calendar\CalendarRequestResource;
use App\Services\Availability\AvailabilityBlocks;
use App\Traits\ApiResponse;

/**
 * Her active blocks and open requests for a range of Riyadh dates (§5.3). Active or provider-suspended (D96, D99).
 */
class ShowCalendarController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly AvailabilityBlocks $blocks) {}

    public function __invoke(ShowCalendarRequest $request)
    {
        $provider = $this->blocks->providerOf($request->user());
        $from = $request->validated('from');
        $to = $request->validated('to');
        $calendar = $this->blocks->calendar($provider, $from, $to);

        return $this->success([
            'from' => $from,
            'to' => $to,
            'blocks' => BlockResource::collection($calendar['blocks'])->resolve($request),
            'requests' => CalendarRequestResource::collection($calendar['requests'])->resolve($request),
        ]);
    }
}
