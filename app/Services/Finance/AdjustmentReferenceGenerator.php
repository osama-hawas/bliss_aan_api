<?php

namespace App\Services\Finance;

/**
 * `LA-` + 7 random digits (slice-19-contract.md §3). Display only; uniqueness is the database's job and
 * LedgerAdjustments retries on a collision.
 */
class AdjustmentReferenceGenerator
{
    public function next(): string
    {
        return 'LA-'.str_pad((string) random_int(0, 9_999_999), 7, '0', STR_PAD_LEFT);
    }
}
