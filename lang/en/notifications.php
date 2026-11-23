<?php

// Slice 8 notification texts (slice-8-contract.md §6). Placeholders: :name (client first name or provider display
// name), :reference (request/booking reference), :service (service name) — nothing else (D141).
return [
    'someone' => 'A user',
    'events' => [
        'direct_request_received' => ['title' => 'New request', 'body' => ':name sent you a request for :service (:reference).'],
        'open_request_received' => ['title' => 'New open request', 'body' => 'A new request for :service matches your services (:reference).'],
        'request_cancelled' => ['title' => 'Request cancelled', 'body' => ':name cancelled request :reference.'],
        'offer_received' => ['title' => 'New offer', 'body' => ':name sent you an offer for :service (:reference).'],
        'offer_withdrawn' => ['title' => 'Offer withdrawn', 'body' => ':name withdrew her offer and declined request :reference.'],
        'request_declined' => ['title' => 'Request declined', 'body' => ':name declined request :reference.'],
        'offer_expired' => ['title' => 'Offer expired', 'body' => 'Your offer on request :reference has expired.'],
        'offer_accepted' => ['title' => 'Offer accepted', 'body' => ':name accepted your offer. Booking :reference is confirmed.'],
        'offer_rejected' => ['title' => 'Offer rejected', 'body' => 'The client rejected your offer on request :reference. You can send a new offer.'],
        'offer_not_selected' => ['title' => 'Offer not selected', 'body' => 'Another offer was chosen for request :reference.'],
        'booking_started' => ['title' => 'Service started', 'body' => ':name started booking :reference.'],
        'booking_completed' => ['title' => 'Service completed', 'body' => 'Booking :reference is complete. Rate your experience with :name.'],
        'cancellation_approved' => ['title' => 'Cancellation approved', 'body' => 'Your cancellation request for booking :reference was approved.'],
        'cancellation_rejected' => ['title' => 'Cancellation not approved', 'body' => 'Your cancellation request for booking :reference was not approved. The booking stays confirmed.'],
        'booking_cancelled' => ['title' => 'Booking cancelled', 'body' => 'Booking :reference was cancelled.'],
        'review_received' => ['title' => 'New review', 'body' => ':name reviewed booking :reference.'],
        'review_replied' => ['title' => 'Reply to your review', 'body' => ':name replied to your review.'],
        'chat_message' => ['title' => 'New message', 'body' => 'New message from :name.'],
        'application_activated' => ['title' => 'Account activated', 'body' => 'Your provider account is active. You can now receive requests.'],
        'application_needs_completion' => ['title' => 'Application needs completion', 'body' => 'Your application needs a few changes. Open it to see what is required.'],
        'application_suspended' => ['title' => 'Account suspended', 'body' => 'Your provider account was suspended. Open it for details.'],
        'application_reactivated' => ['title' => 'Account reactivated', 'body' => 'Your provider account is active again.'],
        'application_document_rejected' => ['title' => 'Document not accepted', 'body' => 'A document in your application was not accepted. Open it to upload a new one.'],
        // Slice 9b — store orders (E26–E29). Placeholder :reference only.
        'store_order_confirmed' => ['title' => 'Order confirmed', 'body' => 'Your order :reference is confirmed. We are preparing it.'],
        'store_order_shipped' => ['title' => 'Order on the way', 'body' => 'Your order :reference is on its way.'],
        'store_order_delivered' => ['title' => 'Order delivered', 'body' => 'Your order :reference was delivered.'],
        'store_order_cancelled' => ['title' => 'Order cancelled', 'body' => 'Your order :reference was cancelled. Open it for details.'],
        // Slice 11 (D224): E18, E19.
        'payout_paid' => ['title' => 'Payout transferred', 'body' => 'Your payout :reference was transferred to your bank account.'],
        'payout_rejected' => ['title' => 'Payout request rejected', 'body' => 'Your payout request :reference was rejected. Open it to see the reason.'],
        // Slice 17 (E20–E25). :amount is a display amount (E25 only).
        'support_replied' => ['title' => 'Support replied', 'body' => 'Bliss Tale support replied to your ticket :reference.'],
        'support_ticket_resolved' => ['title' => 'Ticket resolved', 'body' => 'Your ticket :reference was marked resolved. Reply if you still need help.'],
        'support_ticket_closed' => ['title' => 'Ticket closed', 'body' => 'Your ticket :reference was closed. Open a new ticket if you need more help.'],
        'request_cancelled_by_staff' => ['title' => 'Request cancelled', 'body' => 'Bliss Tale support cancelled request :reference.'],
        'booking_cancelled_by_staff' => ['title' => 'Booking cancelled', 'body' => 'Bliss Tale support cancelled booking :reference.'],
        'booking_completed_by_staff' => ['title' => 'Booking completed', 'body' => 'Bliss Tale support marked booking :reference as completed.'],
        'refund_issued' => ['title' => 'Refund issued', 'body' => 'A refund of :amount was issued for :reference.'],
        // Slice 19 (S19).
        'ledger_credit_posted' => ['title' => 'Balance credited', 'body' => ':amount :currency was added to your balance (:reference).'],
        'ledger_debit_posted' => ['title' => 'Balance adjusted', 'body' => ':amount :currency was deducted from your balance (:reference).'],
        // S20 (E40): the campaign's own title and text, in the reader's language (D306).
        'broadcast' => ['title' => ':title', 'body' => ':body'],
        // S22 (E50).
        'account_deletion_blocked' => ['title' => 'Account deletion on hold', 'body' => 'We could not delete your account yet: it still has an open booking, order, return, payout or ticket. It will be deleted once they are closed.'],
        // S23 — store returns (E55–E59).
        'store_return_requested' => ['title' => 'Return requested', 'body' => 'We received your return request :reference. We will review it soon.'],
        'store_return_approved' => ['title' => 'Return approved', 'body' => 'Your return :reference was approved. Open it to see how to send the items back.'],
        'store_return_rejected' => ['title' => 'Return not accepted', 'body' => 'Your return :reference was not accepted. Open it for the reason.'],
        'store_return_received' => ['title' => 'Return received', 'body' => 'We received the items of your return :reference. Your refund is on its way.'],
        'store_return_refunded' => ['title' => 'Refund issued', 'body' => 'A refund of :amount was issued for your return :reference.'],
    ],
];
