<?php

namespace App\Services\Bookings;

/**
 * `BK-` + 7 random digits. Display only, never an authorization key; OfferAcceptor retries on a collision.
 */
class BookingReferenceGenerator
{
    public function next(): string
    {
        return 'BK-'.str_pad((string) random_int(0, 9_999_999), 7, '0', STR_PAD_LEFT);
    }
}
