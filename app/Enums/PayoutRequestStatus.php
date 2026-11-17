<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/**
 * Payout request (D216): staff mark it paid or reject it. Both are terminal. `processing` / `failed` are not built.
 */
enum PayoutRequestStatus: string
{
    use HasLabel;

    case Requested = 'requested';
    case Paid = 'paid';
    case Rejected = 'rejected';
}
