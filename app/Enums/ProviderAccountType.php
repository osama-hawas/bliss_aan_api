<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

enum ProviderAccountType: string
{
    use HasLabel;

    case Individual = 'individual';
    case Establishment = 'establishment';
}
