<?php

namespace App\Http\Controllers\Api\V1\Admin\Store;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Store\ListStockMovementsRequest;
use App\Http\Resources\Api\V1\Admin\Store\AdminStockMovementResource;
use App\Services\Store\Admin\ProductManagement;
use App\Services\Store\Admin\StockAdjustments;
use App\Support\Pagination;
use App\Traits\ApiResponse;

/**
 * `products.manage` (D279): the product's stock history, newest first.
 */
class ListStockMovementsController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly StockAdjustments $stock) {}

    public function __invoke(ListStockMovementsRequest $request, int $product)
    {
        $product = app(ProductManagement::class)->find($product);
        $variantId = $request->filled('filter.variant_id') ? (int) $request->validated('filter.variant_id') : null;

        return $this->success(AdminStockMovementResource::collection($this->stock->history($product, $variantId, Pagination::perPage($request))));
    }
}
