<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/**
 * Who may see an activity_events row in "view history".
 */
enum ActivityVisibility: string
{
    use HasLabel;

    case Client = 'client';
    case Provider = 'provider';
    case Internal = 'internal';
    case All = 'all';
}
