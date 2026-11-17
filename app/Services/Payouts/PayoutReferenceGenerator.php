<?php

namespace App\Services\Payouts;

/**
 * `PO-` + 7 random digits. Display only; the database unique key decides, callers retry on a collision.
 */
class PayoutReferenceGenerator
{
    public function next(): string
    {
        return 'PO-'.str_pad((string) random_int(0, 9_999_999), 7, '0', STR_PAD_LEFT);
    }
}
