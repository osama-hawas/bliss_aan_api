<?php

namespace App\Http\Controllers\Api\V1\Admin\Operations;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Operations\StaffNoteRequest;
use App\Http\Resources\Api\V1\Admin\ServiceRequests\AdminRequestResource;
use App\Services\Operations\RequestInterventions;
use App\Services\ServiceRequests\ServiceRequestQueries;
use App\Traits\ApiResponse;

/**
 * Internal staff note on a request (`bookings.intervene`).
 */
class AddServiceRequestNoteController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ServiceRequestQueries $queries, private readonly RequestInterventions $interventions) {}

    public function __invoke(StaffNoteRequest $request, int $serviceRequest)
    {
        $this->interventions->addNote($request->user(), $this->queries->adminRequest($serviceRequest), $request->validated('note'));

        return $this->success((new AdminRequestResource($this->queries->adminRequest($serviceRequest)))->detailed(), __('Note added.'), 201);
    }
}
