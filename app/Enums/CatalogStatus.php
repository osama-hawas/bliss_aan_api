<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/**
 * Admin catalog rows (categories, services, add-ons, event types) are stopped, never deleted (BRD; D180).
 * Derived from `is_active`; never stored as a string.
 */
enum CatalogStatus: string
{
    use HasLabel;

    case Active = 'active';
    case Inactive = 'inactive';

    public static function of(bool $isActive): self
    {
        return $isActive ? self::Active : self::Inactive;
    }
}
