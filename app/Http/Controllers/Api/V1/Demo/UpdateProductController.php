<?php

namespace App\Http\Controllers\Api\V1\Demo;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Demo\UpdateProductRequest;
use App\Http\Resources\Api\V1\Demo\ProductResource;
use App\Services\Demo\ProductService;
use App\Traits\ApiResponse;

class UpdateProductController extends Controller
{
    use ApiResponse;

    protected $productService;

    public function __construct(ProductService $productService)
    {
        $this->productService = $productService;
    }

    public function __invoke(UpdateProductRequest $request, $id)
    {
        $product = $this->productService->update((int)$id, $request->validated());

        return $this->success(
            new ProductResource($product),
            'Product updated successfully.'
        );
    }
}
