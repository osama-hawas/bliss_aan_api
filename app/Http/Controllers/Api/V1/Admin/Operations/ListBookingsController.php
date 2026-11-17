<?php

namespace App\Http\Controllers\Api\V1\Admin\Operations;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Operations\ListBookingsRequest;
use App\Http\Resources\Api\V1\Admin\Operations\AdminBookingResource;
use App\Services\Operations\BookingAdminQueries;
use App\Support\Pagination;
use App\Traits\ApiResponse;

/**
 * Bookings list (`bookings.view`, §5.1).
 */
class ListBookingsController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly BookingAdminQueries $queries) {}

    public function __invoke(ListBookingsRequest $request)
    {
        return $this->success(AdminBookingResource::collection(
            $this->queries->list($request->filters(), $request->validated('sort'), Pagination::perPage($request))));
    }
}
