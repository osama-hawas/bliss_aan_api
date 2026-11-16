<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/**
 * Reason codes for reporting content (Slice 6: reviews, app design).
 */
enum ReportReason: string
{
    use HasLabel;

    case Abusive = 'abusive';
    case Spam = 'spam';
    case FalseInfo = 'false_info';
    case Privacy = 'privacy';
    case Other = 'other';
}
