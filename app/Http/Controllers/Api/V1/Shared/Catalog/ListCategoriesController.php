<?php

namespace App\Http\Controllers\Api\V1\Shared\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Shared\Catalog\CategoryResource;
use App\Services\Catalog\CatalogBrowser;
use App\Traits\ApiResponse;

class ListCategoriesController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly CatalogBrowser $catalog) {}

    /**
     * Active service categories. Public.
     */
    public function __invoke()
    {
        return $this->success(CategoryResource::collection($this->catalog->categories()));
    }
}
