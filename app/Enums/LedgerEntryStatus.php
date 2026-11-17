<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/**
 * Derived on read from `available_at` (slice-11-contract.md §4.3): never stored.
 */
enum LedgerEntryStatus: string
{
    use HasLabel;

    case Pending = 'pending';
    case Available = 'available';
}
