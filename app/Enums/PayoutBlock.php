<?php

namespace App\Enums;

/**
 * Why she cannot request a payout now (slice-11-contract.md §5 `request_block`), in check order.
 */
enum PayoutBlock: string
{
    case ProviderInactive = 'provider_inactive';
    case NoAccount = 'no_account';
    case OpenRequest = 'open_request';
    case NothingAvailable = 'nothing_available';
    case BelowMinimum = 'below_minimum';
}
