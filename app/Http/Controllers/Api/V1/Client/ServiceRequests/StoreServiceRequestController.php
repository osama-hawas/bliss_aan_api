<?php

namespace App\Http\Controllers\Api\V1\Client\ServiceRequests;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Client\ServiceRequests\StoreServiceRequestRequest;
use App\Http\Resources\Api\V1\Client\ServiceRequests\ClientRequestResource;
use App\Services\ServiceRequests\ServiceRequestCreator;
use App\Services\ServiceRequests\ServiceRequestQueries;
use App\Traits\ApiResponse;

/**
 * 201 on creation; a replay with the same Idempotency-Key and body answers 200 with the same request.
 */
class StoreServiceRequestController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ServiceRequestCreator $creator, private readonly ServiceRequestQueries $queries) {}

    public function __invoke(StoreServiceRequestRequest $request)
    {
        [$created, $isNew] = $this->creator->create($request->user(), $request->validated('idempotency_key'), $request->body());
        $resource = (new ClientRequestResource($this->queries->clientRequest($request->user(), $created->id)))->detailed();

        return $this->success($resource, __('Request sent.'), $isNew ? 201 : 200);
    }
}
