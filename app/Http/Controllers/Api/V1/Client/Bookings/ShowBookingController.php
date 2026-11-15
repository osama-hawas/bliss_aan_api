<?php

namespace App\Http\Controllers\Api\V1\Client\Bookings;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Client\Bookings\ClientBookingResource;
use App\Services\Bookings\BookingQueries;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

class ShowBookingController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly BookingQueries $bookings) {}

    public function __invoke(Request $request, int $booking)
    {
        return $this->success(new ClientBookingResource($this->bookings->clientBooking($request->user(), $booking)));
    }
}
