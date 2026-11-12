<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/**
 * Direct = one chosen provider; open = every matching provider (D55). A direct request never becomes open.
 */
enum ServiceRequestType: string
{
    use HasLabel;

    case Direct = 'direct';
    case Open = 'open';
}
