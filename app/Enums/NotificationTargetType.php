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
    case StoreOrder = 'store_order'; // Slice 9b
    case PayoutRequest = 'payout_request'; // Slice 11 (D224)
    case SupportTicket = 'support_ticket'; // Slice 17 (E20, E21)
    case Earnings = 'earnings'; // Slice 19 (S19): the provider's earnings screen, no id
    case Broadcast = 'broadcast'; // S20 (D306): no deep link, opens the list
}
