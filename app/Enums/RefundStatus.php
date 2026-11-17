<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/**
 * Slice 10: a refund is reserved `in_progress` before the gateway call and settles once.
 */
enum RefundStatus: string
{
    use HasLabel;

    case InProgress = 'in_progress';
    case Refunded = 'refunded';
    case Failed = 'failed';

    public function isTerminal(): bool
    {
        return $this !== self::InProgress;
    }
}
