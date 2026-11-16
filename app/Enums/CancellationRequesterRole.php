<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/**
 * Who asked to cancel a booking. `provider` = inability to fulfil (Req 24).
 */
enum CancellationRequesterRole: string
{
    use HasLabel;

    case Client = 'client';
    case Provider = 'provider';
}
