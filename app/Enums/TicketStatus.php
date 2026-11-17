<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/**
 * Support ticket states (D160). Mobile users only open tickets and reply; every other move belongs to Slice 17.
 */
enum TicketStatus: string
{
    use HasLabel;

    case Open = 'open';
    case InProgress = 'in_progress';
    case Waiting = 'waiting';
    case Resolved = 'resolved';
    case Closed = 'closed';

    public function acceptsReplies(): bool
    {
        return $this !== self::Closed;
    }

    /**
     * Where her reply moves the ticket (D160): a resolved ticket reopens, a waiting one goes back to the team.
     */
    public function afterUserReply(): self
    {
        return match ($this) {
            self::Resolved => self::Open,
            self::Waiting => self::InProgress,
            default => $this,
        };
    }
}
