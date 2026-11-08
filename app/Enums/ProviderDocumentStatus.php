<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/**
 * A provider document under review. Rejected documents are replaced by a new upload.
 */
enum ProviderDocumentStatus: string
{
    use HasLabel;

    case Pending = 'pending';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
}
