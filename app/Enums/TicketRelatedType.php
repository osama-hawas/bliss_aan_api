<?php

namespace App\Enums;

/**
 * The record a support ticket may point at (D159). Store orders are added when `store_orders` exist.
 */
enum TicketRelatedType: string
{
    case ServiceRequest = 'service_request';
    case Booking = 'booking';
}
