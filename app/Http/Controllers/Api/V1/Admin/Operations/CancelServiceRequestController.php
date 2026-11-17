<?php

namespace App\Http\Controllers\Api\V1\Admin\Operations;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Operations\InterventionRequest;
use App\Http\Resources\Api\V1\Admin\ServiceRequests\AdminRequestResource;
use App\Services\Operations\RequestInterventions;
use App\Services\ServiceRequests\ServiceRequestQueries;
use App\Traits\ApiResponse;

/**
 * Staff cancel an open request (`bookings.intervene`, D256).
 */
class CancelServiceRequestController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ServiceRequestQueries $queries, private readonly RequestInterventions $interventions) {}

    public function __invoke(InterventionRequest $request, int $serviceRequest)
    {
        $this->interventions->cancel($request->user(), $this->queries->adminRequest($serviceRequest), $request->validated('reason'), $request->validated('internal_note'));

        return $this->success((new AdminRequestResource($this->queries->adminRequest($serviceRequest)))->detailed(), __('Request cancelled.'));
    }
}
