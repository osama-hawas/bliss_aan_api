<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/**
 * Whether a catalog service asks for the number of guests (D54).
 */
enum GuestCountMode: string
{
    use HasLabel;

    case Hidden = 'hidden';
    case Optional = 'optional';
    case Required = 'required';
}
