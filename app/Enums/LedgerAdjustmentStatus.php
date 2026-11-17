<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/**
 * Staff ledger adjustment (Slice 19, D288, D289). Terminal: posted, rejected, cancelled.
 */
enum LedgerAdjustmentStatus: string
{
    use HasLabel;

    case PendingApproval = 'pending_approval';
    case Posted = 'posted';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
}
