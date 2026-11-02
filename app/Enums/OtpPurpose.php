<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

enum OtpPurpose: string
{
    use HasLabel;

    case Login = 'login';
    case Register = 'register';
    case ChangePhone = 'change_phone';
}
