<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/**
 * Slice 6 writes `open` only; handling is Slice 17.
 */
enum ContentReportStatus: string
{
    use HasLabel;

    case Open = 'open';
    case Handled = 'handled';
}
