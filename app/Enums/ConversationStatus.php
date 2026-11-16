<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/**
 * Computed from the request lifecycle (slice-7-contract.md §3); never stored. `closed` arrives with Slice 6.
 */
enum ConversationStatus: string
{
    use HasLabel;

    case Active = 'active';
    case ReadOnly = 'read_only';
}
