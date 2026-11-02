<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

enum DocumentAppliesTo: string
{
    use HasLabel;

    case Individual = 'individual';
    case Establishment = 'establishment';
    case Both = 'both';
}
