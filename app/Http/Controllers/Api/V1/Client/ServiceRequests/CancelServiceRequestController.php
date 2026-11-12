<?php

namespace App\Http\Controllers\Api\V1\Client\ServiceRequests;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Client\ServiceRequests\CancelServiceRequestRequest;
use App\Http\Resources\Api\V1\Client\ServiceRequests\ClientRequestResource;
use App\Services\ServiceRequests\ServiceRequestLifecycle;
use App\Services\ServiceRequests\ServiceRequestQueries;
use App\Traits\ApiResponse;
use Illuminate\Support\Facades\Gate;

class CancelServiceRequestController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ServiceRequestQueries $queries, private readonly ServiceRequestLifecycle $lifecycle) {}

    public function __invoke(CancelServiceRequestRequest $request, int $serviceRequest)
    {
        $model = $this->queries->clientRequest($request->user(), $serviceRequest);
        Gate::authorize('cancel', $model);
        $this->lifecycle->cancel($request->user(), $model, $request->validated('reason'));

        return $this->success((new ClientRequestResource($this->queries->clientRequest($request->user(), $model->id)))->detailed(), __('Request cancelled.'));
    }
}
