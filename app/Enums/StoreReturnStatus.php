<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/**
 * Store return states (S23, OD37, D341): requested → approved → received → refunded, or rejected (staff, from requested)
 * / cancelled (the client, while requested).
 */
enum StoreReturnStatus: string
{
    use HasLabel;

    case Requested = 'requested';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Received = 'received';
    case Refunded = 'refunded';
    case Cancelled = 'cancelled';

    public function canMoveTo(self $to): bool
    {
        return in_array($to, match ($this) {
            self::Requested => [self::Approved, self::Rejected, self::Cancelled],
            self::Approved => [self::Received],
            self::Received => [self::Refunded],
            self::Rejected, self::Refunded, self::Cancelled => [],
        }, true);
    }

    /** Still counts against the order's returnable quantities (D343). */
    public function holdsQuantity(): bool
    {
        return ! in_array($this, [self::Rejected, self::Cancelled], true);
    }

    /** Waiting on staff (requested / approved / received with no settled refund). */
    public function isOpen(): bool
    {
        return in_array($this, [self::Requested, self::Approved, self::Received], true);
    }

    /** @return list<string> */
    public static function holdingValues(): array
    {
        return array_values(array_map(fn (self $s) => $s->value, array_filter(self::cases(), fn (self $s) => $s->holdsQuantity())));
    }
}
