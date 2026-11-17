<?php

namespace App\Enums;

/**
 * Notification events of Slice 8 (slice-8-contract.md §6, D133–D135, D142). The value is stored in
 * `notifications.type` and sent as `event` in the push data.
 */
enum NotificationEvent: string
{
    case DirectRequestReceived = 'direct_request_received';   // E1
    case OpenRequestReceived = 'open_request_received';       // E2
    case RequestCancelled = 'request_cancelled';              // E3
    case OfferReceived = 'offer_received';                    // E4
    case OfferWithdrawn = 'offer_withdrawn';                  // E5 (direct requests only, D245)
    case RequestDeclined = 'request_declined';                // E6
    case OfferExpired = 'offer_expired';                      // E7
    case OfferAccepted = 'offer_accepted';                    // E8
    case OfferNotSelected = 'offer_not_selected';             // E9
    case BookingStarted = 'booking_started';                  // E10
    case BookingCompleted = 'booking_completed';              // E11
    case CancellationApproved = 'cancellation_approved';      // E12
    case CancellationRejected = 'cancellation_rejected';      // E12
    case BookingCancelled = 'booking_cancelled';              // E13
    case ReviewReceived = 'review_received';                  // E14
    case ReviewReplied = 'review_replied';                    // E15
    case ChatMessage = 'chat_message';                        // E16 (push only, D142)
    case ApplicationActivated = 'application_activated';      // E17
    case ApplicationNeedsCompletion = 'application_needs_completion';
    case ApplicationSuspended = 'application_suspended';
    case ApplicationReactivated = 'application_reactivated';
    case ApplicationDocumentRejected = 'application_document_rejected';

    /**
     * The D26 key that can switch off the push (D135). Null = transactional, always pushed.
     */
    public function preferenceKey(): ?NotificationPreferenceKey
    {
        return match ($this) {
            self::DirectRequestReceived, self::OpenRequestReceived => NotificationPreferenceKey::NewRequests,
            self::OfferReceived, self::OfferWithdrawn => NotificationPreferenceKey::Offers,
            self::ChatMessage => NotificationPreferenceKey::Messages,
            default => null,
        };
    }

    /**
     * Whether the event is kept in the notification list. Chat messages are push only (D142).
     */
    public function storesRow(): bool
    {
        return $this !== self::ChatMessage;
    }
}
