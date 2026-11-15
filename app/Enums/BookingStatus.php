<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/**
 * Booking lifecycle (BRD §5). Slice 5c creates `confirmed` only; the other moves are Slice 6.
 */
enum BookingStatus: string
{
    use HasLabel;

    case Confirmed = 'confirmed';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    /**
     * Statuses that make the provider busy (D110).
     *
     * @return list<self>
     */
    public static function busy(): array
    {
        return [self::Confirmed, self::InProgress];
    }
}
