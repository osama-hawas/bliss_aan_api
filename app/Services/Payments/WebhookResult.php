<?php

namespace App\Services\Payments;

/**
 * Outcome of one webhook delivery (stored on payment_webhook_events.result).
 */
enum WebhookResult: string
{
    case Applied = 'applied';
    case Ignored = 'ignored';
    case Unmatched = 'unmatched';
    case Duplicate = 'duplicate';
}
