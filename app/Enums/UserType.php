<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

enum UserType: string
{
    use HasLabel;

    case Client = 'client';
    case Provider = 'provider';
    case Admin = 'admin';
}
