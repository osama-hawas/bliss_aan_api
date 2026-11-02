<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/**
 * Which mobile app a piece of content (page, FAQ, banner) targets.
 */
enum Audience: string
{
    use HasLabel;

    case Client = 'client';
    case Provider = 'provider';
}
