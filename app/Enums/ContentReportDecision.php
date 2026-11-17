<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/**
 * How staff closed a content report (Slice 17, D262). `actioned` hides the review; `dismissed` leaves it.
 */
enum ContentReportDecision: string
{
    use HasLabel;

    case Actioned = 'actioned';
    case Dismissed = 'dismissed';
}
