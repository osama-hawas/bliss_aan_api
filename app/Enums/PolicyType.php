<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

enum PolicyType: string
{
    use HasLabel;

    case Payment = 'payment';
    case Cancellation = 'cancellation';
    case Commission = 'commission';
    case StoreReturn = 'store_return';
    case Terms = 'terms';
}
