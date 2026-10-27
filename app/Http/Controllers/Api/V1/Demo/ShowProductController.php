<?php

namespace App\Http\Controllers\Api\V1\Demo;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Demo\ProductResource;
use App\Services\Demo\ProductService;
use App\Traits\ApiResponse;

class ShowProductController extends Controller
{
    use ApiResponse;

    protected $productService;

    public function __construct(ProductService $productService)
    {
        $this->productService = $productService;
    }

    public function __invoke($id)
    {
        $product = $this->productService->find((int)$id);

        return $this->success(
            new ProductResource($product),
            'Product details retrieved successfully.'
        );
    }
}
