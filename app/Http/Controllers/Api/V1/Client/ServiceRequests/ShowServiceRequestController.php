<?php

namespace App\Http\Controllers\Api\V1\Client\ServiceRequests;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Client\ServiceRequests\ClientRequestResource;
use App\Services\ServiceRequests\ServiceRequestQueries;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ShowServiceRequestController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ServiceRequestQueries $queries) {}

    public function __invoke(Request $request, int $serviceRequest)
    {
        $model = $this->queries->clientRequest($request->user(), $serviceRequest);
        Gate::authorize('viewAsClient', $model);

        return $this->success((new ClientRequestResource($model))->detailed());
    }
}
