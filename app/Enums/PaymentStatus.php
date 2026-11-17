<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/**
 * Slice 10 (slice-10-contract.md §6). Only PaymentStateMachine::apply writes it. Terminal states never move, except a
 * late `succeeded` on a `cancelled` attempt, which is recorded and then refunded automatically (§6.4).
 */
enum PaymentStatus: string
{
    use HasLabel;

    case Pending = 'pending';
    case RequiresAction = 'requires_action';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Unknown = 'unknown';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Succeeded, self::Failed, self::Cancelled], true);
    }

    /**
     * @return list<self>
     */
    public static function open(): array
    {
        return [self::Pending, self::RequiresAction, self::Unknown];
    }

    public function canMoveTo(self $to): bool
    {
        if ($this === $to) {
            return false;
        }

        return match ($this) {
            self::Pending => true,
            self::RequiresAction => in_array($to, [self::Succeeded, self::Failed, self::Cancelled, self::Unknown], true),
            self::Unknown => in_array($to, [self::Succeeded, self::Failed, self::Cancelled, self::RequiresAction], true),
            self::Cancelled => $to === self::Succeeded,
            self::Succeeded, self::Failed => false,
        };
    }
}
