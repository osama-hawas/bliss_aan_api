<?php

namespace App\Integrations\Push;

enum PushResult: string
{
    case Sent = 'sent';
    /** The token is no longer valid; the device's token is cleared (D139). */
    case InvalidToken = 'invalid_token';
    /** Temporary failure (429, 5xx, network): try again with backoff. */
    case Retry = 'retry';
    /** Permanent failure for this message: logged, not retried. */
    case Failed = 'failed';
}
