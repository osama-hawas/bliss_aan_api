<?php

namespace App\Http\Controllers\Api\V1\Admin\Operations;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Admin\Operations\AdminBookingResource;
use App\Services\Operations\BookingAdminQueries;
use App\Traits\ApiResponse;

/**
 * One booking, complete (`bookings.view`, §5.1).
 */
class ShowBookingController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly BookingAdminQueries $queries) {}

    public function __invoke(int $booking)
    {
        return $this->success((new AdminBookingResource($this->queries->find($booking)))->detailed());
    }
}
