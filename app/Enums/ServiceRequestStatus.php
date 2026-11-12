<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/**
 * Request lifecycle (BRD §5, D60). Slice 4 moves: created → awaiting_offers; client cancel; a direct request's
 * provider declines → declined. Offers and bookings (Slices 5–6) add the rest.
 */
enum ServiceRequestStatus: string
{
    use HasLabel;

    case AwaitingOffers = 'awaiting_offers';
    case OffersReceived = 'offers_received';
    case Confirmed = 'confirmed';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Declined = 'declined';

    /**
     * Still before an accepted offer: the client may cancel and a recipient may decline.
     */
    public function isOpenForResponses(): bool
    {
        return in_array($this, [self::AwaitingOffers, self::OffersReceived], true);
    }
}
