<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/**
 * One provider's position on a request (D60). `offer_sent` arrives with Slice 5.
 */
enum RecipientStatus: string
{
    use HasLabel;

    case New = 'new';
    case OfferSent = 'offer_sent';
    case Declined = 'declined';
    case Closed = 'closed';

    /**
     * Still taking part: she sees the attachments (D58) and the request can be closed for her.
     */
    public function isActive(): bool
    {
        return in_array($this, [self::New, self::OfferSent], true);
    }
}
