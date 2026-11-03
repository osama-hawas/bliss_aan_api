<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/**
 * Provider application lifecycle. Only `active` providers are visible to clients.
 */
enum ProviderStatus: string
{
    use HasLabel;

    case Draft = 'draft';
    case Submitted = 'submitted';
    case NeedsCompletion = 'needs_completion';
    case Active = 'active';
    case Suspended = 'suspended';
}
