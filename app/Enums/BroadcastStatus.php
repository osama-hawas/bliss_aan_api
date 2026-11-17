<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/**
 * Broadcast campaign lifecycle (Slice 20, D305): draft → scheduled → sending → sent; draft|scheduled → cancelled.
 */
enum BroadcastStatus: string
{
    use HasLabel;

    case Draft = 'draft';
    case Scheduled = 'scheduled';
    case Sending = 'sending';
    case Sent = 'sent';
    case Cancelled = 'cancelled';

    public function canMoveTo(self $to): bool
    {
        return in_array($to, match ($this) {
            self::Draft => [self::Scheduled, self::Sending, self::Cancelled],
            self::Scheduled => [self::Sending, self::Cancelled, self::Draft],
            self::Sending => [self::Sent],
            self::Sent, self::Cancelled => [],
        }, true);
    }
}
