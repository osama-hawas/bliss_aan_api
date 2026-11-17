<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/**
 * Slice 10: full payment of a booking offer (D185) or a store order. Deposits are not built.
 */
enum PaymentPurpose: string
{
    use HasLabel;

    case Full = 'full';
    case Store = 'store';
}
