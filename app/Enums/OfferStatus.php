<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/**
 * Offer lifecycle (slice-5b-contract.md §2). `selected` / `not_selected` arrive with acceptance in 5c (D4).
 * `withdrawn` is D91. A stored `active` offer past `valid_until` is presented as `expired` (Offer::effectiveStatus).
 */
enum OfferStatus: string
{
    use HasLabel;

    case Active = 'active';
    case Superseded = 'superseded';
    case Withdrawn = 'withdrawn';
    case Expired = 'expired';
    case Closed = 'closed';
    case Selected = 'selected';
    case NotSelected = 'not_selected';

    /**
     * Statuses a client may see: never a replaced or withdrawn offer (§3.6).
     *
     * @return list<string>
     */
    public static function clientVisible(): array
    {
        return [self::Active->value, self::Expired->value, self::Closed->value, self::Selected->value, self::NotSelected->value];
    }
}
