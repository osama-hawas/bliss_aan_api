<?php

// Labels of history events (activity_events.event) shown to users.
return [
    'request_created' => 'Request sent',
    'request_cancelled' => 'Request cancelled',
    'request_declined' => 'Request declined by the provider',
    'recipient_declined' => 'A provider declined',
    'offer_sent' => 'Offer received',
    'offer_withdrawn' => 'A provider withdrew her offer',
    'booking_confirmed' => 'Offer accepted, booking confirmed',
    'booking_started' => 'Service started',
    'booking_completed' => 'Service completed',
    'cancellation_requested' => 'Cancellation requested',
    'cancellation_withdrawn' => 'Cancellation request withdrawn',
    'cancellation_rejected' => 'Cancellation not approved',
    'booking_cancelled' => 'Booking cancelled',
    'review_published' => 'Review published',
    'wallet_created' => 'Wallet created',
    'wallet_closed' => 'Wallet closed',
    // Slice 12b
    'ticket_opened' => 'Support ticket opened',
    'ticket_replied' => 'Reply sent to support',
    // Slice 10 (payments, dashboard history).
    'payment_pending' => 'Payment processing',
    'payment_requires_action' => 'Waiting for bank confirmation',
    'payment_succeeded' => 'Payment succeeded',
    'payment_failed' => 'Payment failed',
    'payment_cancelled' => 'Payment attempt cancelled',
    'payment_unknown' => 'Payment result being checked',
    'payment_fulfilment_fulfilled' => 'Booking confirmed after payment',
    'payment_fulfilment_failed' => 'Booking could not be confirmed after payment',
    'refund_requested' => 'Refund started',
    'refund_refunded' => 'Refund completed',
    'refund_failed' => 'Refund failed',
];
