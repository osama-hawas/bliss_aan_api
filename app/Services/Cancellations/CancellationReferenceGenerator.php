<?php

namespace App\Services\Cancellations;

/**
 * `CN-` + 7 random digits. Display only; BookingCancellations retries on a collision.
 */
class CancellationReferenceGenerator
{
    public function next(): string
    {
        return 'CN-'.str_pad((string) random_int(0, 9_999_999), 7, '0', STR_PAD_LEFT);
    }
}
