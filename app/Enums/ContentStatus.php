<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/**
 * Display status of a banner, page or FAQ: the `is_active` flag (Slice 20, D301).
 */
enum ContentStatus: string
{
    use HasLabel;

    case Visible = 'visible';
    case Hidden = 'hidden';

    public static function of(bool $isActive): self
    {
        return $isActive ? self::Visible : self::Hidden;
    }
}
