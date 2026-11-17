<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/**
 * A provider team member (Slice 13, D230): `active ⇄ disabled`, both ways, any time. Nothing is deleted.
 */
enum TeamMemberStatus: string
{
    use HasLabel;

    case Active = 'active';
    case Disabled = 'disabled';
}
