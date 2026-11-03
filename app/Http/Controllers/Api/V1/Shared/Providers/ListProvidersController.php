<?php

namespace App\Http\Controllers\Api\V1\Shared\Providers;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Shared\Providers\ListProvidersRequest;
use App\Http\Resources\Api\V1\Shared\Providers\ProviderCardResource;
use App\Services\Providers\ProviderDirectory;
use App\Support\Pagination;
use App\Traits\ApiResponse;

class ListProvidersController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ProviderDirectory $providers) {}

    /**
     * Search and filter visible providers. Public.
     */
    public function __invoke(ListProvidersRequest $request)
    {
        $page = $this->providers->search($request->filters(), $request->validated('sort'), Pagination::perPage($request));

        return $this->success(ProviderCardResource::collection($page));
    }
}
