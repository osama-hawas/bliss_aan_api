<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/**
 * Where a stock change came from (Slice 18, D279). `order` / `order_cancelled` are reserved for S9b's
 * deduction at confirmation and restoration at cancellation (D207), written after the merge.
 */
enum StockMovementSource: string
{
    use HasLabel;

    case Initial = 'initial';
    case Adjustment = 'adjustment';
    case Order = 'order';
    case OrderCancelled = 'order_cancelled';
}
