<?php

namespace App\Http\Controllers\Api\V1\Provider\Bookings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Shared\ListBookingsRequest;
use App\Http\Resources\Api\V1\Provider\Bookings\ProviderBookingResource;
use App\Services\Availability\AvailabilityBlocks;
use App\Services\Bookings\BookingQueries;
use App\Support\Pagination;
use App\Traits\ApiResponse;

/**
 * Her bookings. Readable while suspended at provider level, like her calendar (D96).
 */
class ListBookingsController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly AvailabilityBlocks $blocks, private readonly BookingQueries $bookings) {}

    public function __invoke(ListBookingsRequest $request)
    {
        $provider = $this->blocks->providerOf($request->user());
        $page = $this->bookings->forProvider($provider, $request->statuses(), $request->validated('sort'), Pagination::perPage($request));

        return $this->success(ProviderBookingResource::collection($page));
    }
}
