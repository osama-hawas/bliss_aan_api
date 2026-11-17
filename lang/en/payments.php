<?php

// Slice 10 (payments): gateway failure texts and automatic refund reasons.
return [
    'failure' => [
        'declined' => 'The card was declined.',
        'insufficient_funds' => 'The card does not have enough balance.',
        'expired_card' => 'The card has expired.',
        'authentication_failed' => 'The payment was not confirmed with your bank.',
        'gateway_error' => 'The payment could not be processed. Please try again.',
    ],
    'refund_reason' => [
        'acceptance_failed' => 'Automatic full refund: the booking could not be confirmed after payment.',
        'superseded_attempt' => 'Automatic full refund: an earlier payment attempt succeeded after it was replaced or expired.',
    ],
    'created' => 'Payment started.',
    'method_saved' => 'Card saved.',
    'method_removed' => 'Card removed.',
    'method_defaulted' => 'Default card updated.',
    'refund_started' => 'Refund started.',
    'fake_checkout_title' => 'Test payment confirmation',
    'fake_checkout_body' => 'This page stands in for the bank confirmation step (test gateway only).',
    'fake_checkout_approve' => 'Approve',
    'fake_checkout_decline' => 'Decline',
    'fake_checkout_done' => 'Done. You can go back to the app.',
];
