<?php

namespace App\Services\Store;

/**
 * Public store order references `SO-` + 7 random digits (`02-database-plan.md` conventions). Collisions are retried by
 * the caller on the unique index. Tests bind a fake to make references predictable.
 */
class StoreOrderReferenceGenerator
{
    public function next(): string
    {
        return 'SO-'.str_pad((string) random_int(0, 9_999_999), 7, '0', STR_PAD_LEFT);
    }
}
