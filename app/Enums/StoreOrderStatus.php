<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/**
 * Store order states (D211, D212; `slice-9b-contract.md` §4.1). `pending_payment` / `expired` are checkout attempts:
 * hidden from the client's list and the admin default list.
 */
enum StoreOrderStatus: string
{
    use HasLabel;

    case PendingPayment = 'pending_payment';
    case Confirmed = 'confirmed';
    case Preparing = 'preparing';
    case Shipped = 'shipped';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';
    case Expired = 'expired';

    public function canMoveTo(self $to): bool
    {
        return in_array($to, match ($this) {
            self::PendingPayment => [self::Confirmed, self::Expired, self::Cancelled],
            self::Confirmed => [self::Preparing, self::Shipped, self::Cancelled],
            self::Preparing => [self::Shipped, self::Cancelled],
            self::Shipped => [self::Delivered, self::Preparing],
            self::Delivered, self::Cancelled, self::Expired => [],
        }, true);
    }

    /** Placed and paid: shown in her order list. */
    public function isPlaced(): bool
    {
        return ! in_array($this, [self::PendingPayment, self::Expired], true);
    }

    /** Stock was deducted and has not left the store yet: cancelling restores it (D207). */
    public function isCancellableByStaff(): bool
    {
        return in_array($this, [self::Confirmed, self::Preparing], true);
    }

    /** @return list<self> */
    public static function active(): array
    {
        return [self::Confirmed, self::Preparing, self::Shipped];
    }

    /** @return list<self> */
    public static function done(): array
    {
        return [self::Delivered, self::Cancelled];
    }
}
