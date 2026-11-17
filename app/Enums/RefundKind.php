<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/**
 * Slice 10: why a refund exists. Only staff start refunds by hand (D187); the other kinds are automatic.
 */
enum RefundKind: string
{
    use HasLabel;

    case AcceptanceFailed = 'acceptance_failed';
    case SupersededAttempt = 'superseded_attempt';
    case Staff = 'staff';
}
