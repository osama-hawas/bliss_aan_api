<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/**
 * A promo use (D212/Q116): `held` by an open checkout, `used` by a paid order, `released` when the attempt expired or
 * the order was cancelled for stock. Held and used uses count against the limits.
 */
enum PromoCodeUsageStatus: string
{
    use HasLabel;

    case Held = 'held';
    case Used = 'used';
    case Released = 'released';

    /** @return list<string> */
    public static function counting(): array
    {
        return [self::Held->value, self::Used->value];
    }
}
