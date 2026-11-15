<?php

namespace App\Services\Offers;

/**
 * `Q-` + 7 random digits. Display only, never an authorization key; OfferSender retries on a collision.
 */
class OfferReferenceGenerator
{
    public function next(): string
    {
        return 'Q-'.str_pad((string) random_int(0, 9_999_999), 7, '0', STR_PAD_LEFT);
    }
}
