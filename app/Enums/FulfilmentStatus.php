<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/**
 * Slice 10: whether the payable was delivered after a successful payment (booking created, order confirmed).
 */
enum FulfilmentStatus: string
{
    use HasLabel;

    case NotApplicable = 'not_applicable';
    case Pending = 'pending';
    case Fulfilled = 'fulfilled';
    case Failed = 'failed';
}
