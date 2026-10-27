<?php

namespace App\Http\Controllers\Api\V1\Demo;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Demo\StoreProductRequest;
use App\Http\Resources\Api\V1\Demo\ProductResource;
use App\Services\Demo\ProductService;
use App\Traits\ApiResponse;

class StoreProductController extends Controller
{
    use ApiResponse;

    protected $productService;

    public function __construct(ProductService $productService)
    {
        $this->productService = $productService;
    }

    public function __invoke(StoreProductRequest $request)
    {
        $product = $this->productService->store($request->validated());

        return $this->success(
            new ProductResource($product),
            'Product created successfully.',
            201
        );
    }
}
