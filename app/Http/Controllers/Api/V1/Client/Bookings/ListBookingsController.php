<?php

namespace App\Http\Controllers\Api\V1\Client\Bookings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Shared\ListBookingsRequest;
use App\Http\Resources\Api\V1\Client\Bookings\ClientBookingResource;
use App\Services\Bookings\BookingQueries;
use App\Support\Pagination;
use App\Traits\ApiResponse;

class ListBookingsController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly BookingQueries $bookings) {}

    public function __invoke(ListBookingsRequest $request)
    {
        $page = $this->bookings->forClient($request->user(), $request->statuses(), $request->validated('sort'), Pagination::perPage($request));

        return $this->success(ClientBookingResource::collection($page));
    }
}
