<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/**
 * Slice 10 (D190): what a payment pays for. Each type has one PayableHandler in PayableRegistry. S9b appends StoreOrder.
 */
enum PayableType: string
{
    use HasLabel;

    case Offer = 'offer';
}
