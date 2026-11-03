<?php

namespace App\Http\Controllers\Api\V1\Shared\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Shared\Catalog\ListServicesRequest;
use App\Http\Resources\Api\V1\Shared\Catalog\ServiceResource;
use App\Services\Catalog\CatalogBrowser;
use App\Traits\ApiResponse;

class ListServicesController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly CatalogBrowser $catalog) {}

    /**
     * Active catalog services, optionally of one category. Public.
     */
    public function __invoke(ListServicesRequest $request)
    {
        return $this->success(ServiceResource::collection($this->catalog->services($request->categoryId())));
    }
}
