<?php

namespace App\Http\Controllers\Api\V1\Admin\ServiceRequests;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Admin\ServiceRequests\AdminRequestResource;
use App\Services\ServiceRequests\ServiceRequestQueries;
use App\Traits\ApiResponse;

class ShowServiceRequestController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ServiceRequestQueries $queries) {}

    public function __invoke(int $serviceRequest)
    {
        return $this->success((new AdminRequestResource($this->queries->adminRequest($serviceRequest)))->detailed());
    }
}
