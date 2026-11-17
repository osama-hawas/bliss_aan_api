<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/**
 * Which way a staff adjustment moves a provider's balance (Slice 19, D286).
 */
enum LedgerAdjustmentDirection: string
{
    use HasLabel;

    case Credit = 'credit';
    case Debit = 'debit';

    /**
     * The signed ledger amount for a positive amount in cents.
     */
    public function signed(int $cents): int
    {
        return $this === self::Credit ? $cents : -$cents;
    }
}
