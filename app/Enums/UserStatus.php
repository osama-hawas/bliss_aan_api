<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

enum UserStatus: string
{
    use HasLabel;

    case Active = 'active';
    case Suspended = 'suspended';
    case PendingDeletion = 'pending_deletion';
}
