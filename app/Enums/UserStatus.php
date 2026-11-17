<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

enum UserStatus: string
{
    use HasLabel;

    case Active = 'active';
    case Suspended = 'suspended';
    case PendingDeletion = 'pending_deletion';
    // S22 (D331): anonymised after an account deletion request was processed. Terminal: no sign-in, no route.
    case Deleted = 'deleted';
}
