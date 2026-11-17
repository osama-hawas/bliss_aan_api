<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/**
 * Slice 10: refund summary of one payment, derived from its refunds.
 */
enum PaymentRefundState: string
{
    use HasLabel;

    case None = 'none';
    case InProgress = 'in_progress';
    case Partial = 'partial';
    case Full = 'full';
}
