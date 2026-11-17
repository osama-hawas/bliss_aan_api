<?php

namespace App\Services\Store\Returns;

/**
 * Public return references `RT-` + 7 random digits. Collisions are retried by the caller on the unique index. Tests
 * bind a fake to make references predictable.
 */
class StoreReturnReferenceGenerator
{
    public function next(): string
    {
        return 'RT-'.str_pad((string) random_int(0, 9_999_999), 7, '0', STR_PAD_LEFT);
    }
}
