<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/**
 * Slice 6 writes `published` only (D129); hiding is review moderation, Slice 17.
 */
enum ReviewStatus: string
{
    use HasLabel;

    case Published = 'published';
    case Hidden = 'hidden';
}
