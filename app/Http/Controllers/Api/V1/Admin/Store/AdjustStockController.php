<?php

namespace App\Http\Controllers\Api\V1\Admin\Store;

use App\Enums\StockAdjustmentReason;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Store\AdjustStockRequest;
use App\Http\Resources\Api\V1\Admin\Store\AdminProductDetailResource;
use App\Services\Store\Admin\ProductManagement;
use App\Services\Store\Admin\StockAdjustments;
use App\Traits\ApiResponse;

/**
 * `products.manage` (D274): sets a new count against the count she saw; `409 STOCK_CHANGED` when it moved.
 */
class AdjustStockController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly StockAdjustments $stock) {}

    public function __invoke(AdjustStockRequest $request, int $variant)
    {
        $row = $this->stock->adjust($variant, (int) $request->validated('stock'), (int) $request->validated('expected_stock'),
            StockAdjustmentReason::from($request->validated('reason_code')), $request->validated('note'), $request->user());

        return $this->success(new AdminProductDetailResource(app(ProductManagement::class)->find($row->product_id)), __('store.admin.messages.stock_adjusted'));
    }
}
