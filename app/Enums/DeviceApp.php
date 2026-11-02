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

    /**
     * The only user type this app signs in; the dashboard is for admin staff (Q9).
     */
    public function userType(): UserType
    {
        return match ($this) {
            self::Client => UserType::Client,
            self::Provider => UserType::Provider,
            self::Dashboard => UserType::Admin,
        };
    }
}
