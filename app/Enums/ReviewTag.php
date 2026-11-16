<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/**
 * Approved review tags (app design, D129).
 */
enum ReviewTag: string
{
    use HasLabel;

    case Punctuality = 'punctuality';
    case ServiceQuality = 'service_quality';
    case Friendly = 'friendly';
    case MatchesQuote = 'matches_quote';
    case CleanOrganized = 'clean_organized';
}
