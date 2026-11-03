<?php

namespace App\Http\Controllers\Api\V1\Shared\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Shared\Catalog\ShowHomeRequest;
use App\Http\Resources\Api\V1\Shared\Catalog\BannerResource;
use App\Http\Resources\Api\V1\Shared\Catalog\CategoryResource;
use App\Http\Resources\Api\V1\Shared\Providers\ProviderCardResource;
use App\Services\Catalog\CatalogBrowser;
use App\Services\Providers\ProviderDirectory;
use App\Traits\ApiResponse;

class ShowHomeController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly CatalogBrowser $catalog,
        private readonly ProviderDirectory $providers,
    ) {}

    /**
     * Client home: banners, categories and admin-featured providers (D32) for the city, when given. Public.
     */
    public function __invoke(ShowHomeRequest $request)
    {
        $cityId = $request->cityId();

        return $this->success([
            'banners' => BannerResource::collection($this->catalog->banners($cityId)),
            'categories' => CategoryResource::collection($this->catalog->categories()),
            'featured_providers' => ProviderCardResource::collection($this->providers->featured($cityId)),
        ]);
    }
}
