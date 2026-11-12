<?php

namespace App\Http\Controllers\Api\V1\Admin\ServiceRequests;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\ServiceRequests\ListServiceRequestsRequest;
use App\Http\Resources\Api\V1\Admin\ServiceRequests\AdminRequestResource;
use App\Services\ServiceRequests\ServiceRequestQueries;
use App\Support\Pagination;
use App\Traits\ApiResponse;

class ListServiceRequestsController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ServiceRequestQueries $queries) {}

    public function __invoke(ListServiceRequestsRequest $request)
    {
        $page = $this->queries->forAdmin($request->filters(), $request->validated('sort'), Pagination::perPage($request));

        return $this->success(AdminRequestResource::collection($page));
    }
}
