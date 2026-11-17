<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/**
 * `pending` → `withdrawn` (by her). `processed` / `rejected` exist for the future processing step but are never
 * written until Q76 is answered (D155).
 */
enum DeletionRequestStatus: string
{
    use HasLabel;

    case Pending = 'pending';
    case Withdrawn = 'withdrawn';
    case Processed = 'processed';
    case Rejected = 'rejected';
}
