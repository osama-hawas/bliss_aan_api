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
    case Staff = 'staff'; // Slice 17 (D256): a staff cancellation, stored already accepted
}
