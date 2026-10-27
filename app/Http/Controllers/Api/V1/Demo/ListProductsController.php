<?php

namespace App\Http\Controllers\Api\V1\Demo;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Demo\ProductResource;
use App\Services\Demo\ProductService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

class ListProductsController extends Controller
{
    use ApiResponse;

    protected $productService;

    public function __construct(ProductService $productService)
    {
        $this->productService = $productService;
    }

    public function __invoke(Request $request)
    {
        $perPage = $request->get('per_page', 15);
        $products = $this->productService->getAll((int)$perPage);

        return $this->success(
            ProductResource::collection($products),
            'Products list retrieved successfully.'
        );
    }
}
