<?php

namespace App\Http\Controllers\Api\V1\Demo;

use App\Http\Controllers\Controller;
use App\Services\Demo\ProductService;
use App\Traits\ApiResponse;

class DeleteProductController extends Controller
{
    use ApiResponse;

    protected $productService;

    public function __construct(ProductService $productService)
    {
        $this->productService = $productService;
    }

    public function __invoke($id)
    {
        $this->productService->delete((int)$id);

        return $this->success(
            null,
            'Product deleted successfully.'
        );
    }
}
