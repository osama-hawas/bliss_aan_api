<?php

namespace App\Http\Controllers\Api\V1\Shared\Store;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Shared\Store\ProductCategoryResource;
use App\Services\Store\ProductCatalog;
use App\Traits\ApiResponse;

class ListProductCategoriesController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ProductCatalog $catalog) {}

    /**
     * Active store categories. Public (Slice 9).
     */
    public function __invoke()
    {
        return $this->success(ProductCategoryResource::collection($this->catalog->categories()));
    }
}
