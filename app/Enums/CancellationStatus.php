<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/**
 * Booking cancellation request (BRD §5, slice-6-contract.md §2). Terminal: accepted, rejected, withdrawn.
 */
enum CancellationStatus: string
{
    use HasLabel;

    case UnderReview = 'under_review';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Withdrawn = 'withdrawn';
}
