<?php

namespace App\Services\ServiceRequests;

/**
 * `RN-` + 7 random digits (D72). Display only: never sequential, never an authorization key. Uniqueness is the
 * database's job; ServiceRequestCreator retries on a collision.
 */
class ReferenceGenerator
{
    public function next(): string
    {
        return 'RN-'.str_pad((string) random_int(0, 9_999_999), 7, '0', STR_PAD_LEFT);
    }
}
