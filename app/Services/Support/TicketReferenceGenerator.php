<?php

namespace App\Services\Support;

/**
 * Ticket references: `TK-` + 7 random digits, retried by the caller on a collision (D159, D72 pattern).
 */
class TicketReferenceGenerator
{
    public function next(): string
    {
        return 'TK-'.str_pad((string) random_int(0, 9_999_999), 7, '0', STR_PAD_LEFT);
    }
}
