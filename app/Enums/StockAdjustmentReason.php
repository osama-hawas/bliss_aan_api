<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/**
 * Why staff changed a variant's stock (Slice 18, D274). `other` needs a note.
 */
enum StockAdjustmentReason: string
{
    use HasLabel;

    case Restock = 'restock';
    case Stocktake = 'stocktake';
    case Damaged = 'damaged';
    case Correction = 'correction';
    case Other = 'other';
}
