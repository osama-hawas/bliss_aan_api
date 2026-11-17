<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/**
 * Provider ledger entry types (slice-11-contract.md §4, D218). Earnings and refund adjustments come from bookings;
 * payout and payout_reversal move money in and out of a payout request.
 */
enum LedgerEntryType: string
{
    use HasLabel;

    case Earning = 'earning';
    case RefundAdjustment = 'refund_adjustment';
    case Payout = 'payout';
    case PayoutReversal = 'payout_reversal';

    /**
     * @return list<string> the types that come from bookings (they carry gross / commission).
     */
    public static function bookingTypes(): array
    {
        return [self::Earning->value, self::RefundAdjustment->value];
    }
}
