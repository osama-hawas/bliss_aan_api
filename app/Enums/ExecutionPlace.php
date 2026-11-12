<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/**
 * Where the client wants the service delivered (BRD Req 14). Open requests use the event location only (D55).
 */
enum ExecutionPlace: string
{
    use HasLabel;

    case EventLocation = 'event_location';
    case ProviderLocation = 'provider_location';
}
