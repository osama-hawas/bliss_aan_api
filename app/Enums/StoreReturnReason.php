<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/**
 * Why the client returns items (S23, D342). The note carries the details.
 */
enum StoreReturnReason: string
{
    use HasLabel;

    case Damaged = 'damaged';
    case WrongItem = 'wrong_item';
    case NotAsDescribed = 'not_as_described';
    case ChangedMind = 'changed_mind';
    case Other = 'other';
}
