<?php

namespace App\Http\Controllers\Api\V1\Provider\ServiceRequests;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Provider\ServiceRequests\ListInboxRequest;
use App\Http\Resources\Api\V1\Provider\ServiceRequests\ProviderRequestResource;
use App\Services\Providers\ProviderSetup;
use App\Services\ServiceRequests\ServiceRequestQueries;
use App\Support\Pagination;
use App\Traits\ApiResponse;

/**
 * Her inbox of direct requests and open opportunities. Active providers only (409 otherwise).
 */
class ListInboxController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ProviderSetup $setup, private readonly ServiceRequestQueries $queries) {}

    public function __invoke(ListInboxRequest $request)
    {
        $provider = $this->setup->activeProvider($request->user());
        $page = $this->queries->inbox($provider, $request->filters(), $request->validated('sort'), Pagination::perPage($request));

        return $this->success(ProviderRequestResource::collection($page));
    }
}
