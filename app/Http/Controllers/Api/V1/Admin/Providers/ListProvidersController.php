<?php

namespace App\Http\Controllers\Api\V1\Admin\Providers;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Providers\ListProvidersRequest;
use App\Http\Resources\Api\V1\Admin\Providers\AdminProviderResource;
use App\Services\Providers\ProviderRoster;
use App\Support\Pagination;
use App\Traits\ApiResponse;

/**
 * `providers.view`: activated providers (active / suspended).
 */
class ListProvidersController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ProviderRoster $roster) {}

    public function __invoke(ListProvidersRequest $request)
    {
        $page = $this->roster->list($request->filters(), $request->validated('sort'), Pagination::perPage($request));

        return $this->success(AdminProviderResource::collection($page));
    }
}
