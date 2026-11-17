<?php

namespace App\Enums;

/**
 * What a notification opens (slice-8-contract.md §8). The app always loads the live record (BRD rule 4).
 */
enum NotificationTargetType: string
{
    case Request = 'request';
    case Offer = 'offer';
    case Booking = 'booking';
    case Conversation = 'conversation';
    case Reviews = 'reviews';
    case ProviderAccount = 'provider_account';
    case SupportTicket = 'support_ticket'; // Slice 17 (E20, E21)
}
