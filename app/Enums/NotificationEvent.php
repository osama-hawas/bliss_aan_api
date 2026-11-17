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
    // Slice 9b — store orders (D213, E26–E29), client, transactional.
    case StoreOrderConfirmed = 'store_order_confirmed';       // E26
    case StoreOrderShipped = 'store_order_shipped';           // E27
    case StoreOrderDelivered = 'store_order_delivered';       // E28
    case StoreOrderCancelled = 'store_order_cancelled';       // E29
    // Slice 11 (D224).
    case PayoutPaid = 'payout_paid';                          // E18
    case PayoutRejected = 'payout_rejected';                  // E19
    // Slice 17 (D257, D263): staff actions — E20–E25 (S11 owns E18–E19, S9b E26–E29)
    case SupportReplied = 'support_replied';                  // E20
    case SupportTicketResolved = 'support_ticket_resolved';   // E21
    case SupportTicketClosed = 'support_ticket_closed';       // E21 (closed straight from an open state: no reply possible)
    case RequestCancelledByStaff = 'request_cancelled_by_staff'; // E22
    case BookingCancelledByStaff = 'booking_cancelled_by_staff'; // E23
    case BookingCompletedByStaff = 'booking_completed_by_staff'; // E24
    case RefundIssued = 'refund_issued';                      // E25

    // Slice 19 (S19, D293): a staff adjustment of her ledger was posted.
    case LedgerCreditPosted = 'ledger_credit_posted';         // E35
    case LedgerDebitPosted = 'ledger_debit_posted';           // E36
    // S20 (Slice 20): broadcast campaign, push gated by `promotions` (D305/D306).
    case Broadcast = 'broadcast';                             // E40
    // S22 (D337): her deletion request is due but open obligations keep the account (once per request).
    case AccountDeletionBlocked = 'account_deletion_blocked'; // E50
    // S23 — store returns (OD37, D347), client, transactional.
    case StoreReturnRequested = 'store_return_requested';     // E55
    case StoreReturnApproved = 'store_return_approved';       // E56
    case StoreReturnRejected = 'store_return_rejected';       // E57
    case StoreReturnReceived = 'store_return_received';       // E58
    case StoreReturnRefunded = 'store_return_refunded';       // E59

    /**
     * The D26 key that can switch off the push (D135). Null = transactional, always pushed.
     */
    public function preferenceKey(): ?NotificationPreferenceKey
    {
        return match ($this) {
            self::DirectRequestReceived, self::OpenRequestReceived => NotificationPreferenceKey::NewRequests,
            self::OfferReceived, self::OfferWithdrawn => NotificationPreferenceKey::Offers,
            self::ChatMessage => NotificationPreferenceKey::Messages,
            self::Broadcast => NotificationPreferenceKey::Promotions, // S20 (D306)
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
