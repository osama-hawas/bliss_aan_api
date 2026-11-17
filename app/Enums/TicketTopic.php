<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/**
 * What a support ticket is about (D159).
 */
enum TicketTopic: string
{
    use HasLabel;

    case ServiceQuality = 'service_quality';
    case LateArrival = 'late_arrival';
    case Payment = 'payment';
    case Cancellation = 'cancellation';
    case Account = 'account';
    case Other = 'other';
}
