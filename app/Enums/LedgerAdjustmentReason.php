<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/**
 * Reason category of a staff ledger adjustment (Slice 19, D292). The free-text reason stays internal.
 */
enum LedgerAdjustmentReason: string
{
    use HasLabel;

    case PayoutReturned = 'payout_returned';      // a paid transfer bounced: credit only, linked payout
    case BookingCorrection = 'booking_correction'; // linked booking of hers required
    case Goodwill = 'goodwill';                    // credit only
    case Penalty = 'penalty';                      // debit only
    case Other = 'other';

    /**
     * The only direction this category allows, or null when both are allowed.
     */
    public function onlyDirection(): ?LedgerAdjustmentDirection
    {
        return match ($this) {
            self::PayoutReturned, self::Goodwill => LedgerAdjustmentDirection::Credit,
            self::Penalty => LedgerAdjustmentDirection::Debit,
            default => null,
        };
    }
}
