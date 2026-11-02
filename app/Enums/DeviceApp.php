<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/**
 * Which app a token/device session belongs to.
 */
enum DeviceApp: string
{
    use HasLabel;

    case Client = 'client';
    case Provider = 'provider';
    case Dashboard = 'dashboard';
}
