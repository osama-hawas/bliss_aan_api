<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/**
 * Why she asks to delete her account (D151). The app shows its own list keyed by these codes.
 */
enum DeletionReason: string
{
    use HasLabel;

    case NotUsing = 'not_using';
    case Privacy = 'privacy';
    case Experience = 'experience';
    case OtherAccount = 'other_account';
    case Other = 'other';
}
