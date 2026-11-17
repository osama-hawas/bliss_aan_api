<?php

namespace App\Http\Controllers\Api\V1\Admin\Operations;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Operations\StaffNoteRequest;
use App\Http\Resources\Api\V1\Admin\Operations\AdminBookingResource;
use App\Services\Operations\BookingAdminQueries;
use App\Services\Operations\BookingInterventions;
use App\Traits\ApiResponse;

/**
 * Internal staff note on a booking (`bookings.intervene`).
 */
class AddBookingNoteController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly BookingAdminQueries $queries, private readonly BookingInterventions $interventions) {}

    public function __invoke(StaffNoteRequest $request, int $booking)
    {
        $this->interventions->addNote($request->user(), $this->queries->find($booking), $request->validated('note'));

        return $this->success((new AdminBookingResource($this->queries->find($booking)))->detailed(), __('Note added.'), 201);
    }
}
