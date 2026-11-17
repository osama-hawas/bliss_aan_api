<?php

namespace App\Http\Controllers\Api\V1\Admin\Operations;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Operations\InterventionRequest;
use App\Http\Resources\Api\V1\Admin\Operations\AdminBookingResource;
use App\Services\Operations\BookingAdminQueries;
use App\Services\Operations\BookingInterventions;
use App\Traits\ApiResponse;

/**
 * Staff mark an in-progress booking completed (`bookings.intervene`, D256).
 */
class CompleteBookingController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly BookingAdminQueries $queries, private readonly BookingInterventions $interventions) {}

    public function __invoke(InterventionRequest $request, int $booking)
    {
        $this->interventions->complete($request->user(), $this->queries->find($booking), $request->validated('reason'), $request->validated('internal_note'));

        return $this->success((new AdminBookingResource($this->queries->find($booking)))->detailed(), __('Booking completed.'));
    }
}
