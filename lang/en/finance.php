<?php

// Slice 19 (S19) — finance: adjustment rules and reconciliation check descriptions.
return [
    'amount_over_max' => 'The largest adjustment allowed is :max.',
    'amount_over_payout' => 'A returned payout cannot be credited with more than its amount (:amount).',
    'direction_not_allowed' => 'This reason does not allow this direction.',
    'booking_required' => 'Choose the booking this correction is for.',
    'booking_not_hers' => 'This booking does not belong to the provider.',
    'payout_required' => 'Choose the payout that was returned.',
    'payout_not_hers' => 'This payout does not belong to the provider.',
    'payout_not_paid' => 'Only a payout marked as paid can be returned.',
    'adjustment_created' => 'Adjustment saved.',
    'adjustment_approved' => 'Adjustment approved and posted.',
    'adjustment_rejected' => 'Adjustment closed.',
    'entry_types' => ['earning' => 'Earning', 'refund_adjustment' => 'Refund correction', 'payout' => 'Payout', 'payout_reversal' => 'Payout reversed', 'adjustment' => 'Manual adjustment'],
    'checks' => [
        'stale_payments' => 'Payments still open after their expiry, or with an unknown gateway result. The payments reconcile job settles them.',
        'fulfilment_pending' => 'Paid, but the booking or order was not confirmed yet. The reconcile job retries.',
        'fulfilment_failed_unrefunded' => 'Paid, confirmation failed, and the money is not fully refunded yet.',
        'refunds_in_progress' => 'Refunds sent to the gateway that have no final result yet, oldest first.',
        'refunds_failed' => 'Failed refunds whose payment still has an amount that can be refunded.',
        'payment_refund_mismatch' => 'The refunded amount on the payment differs from the sum of its refunds.',
        'webhooks_unprocessed' => 'Gateway events that matched no payment or were never processed.',
        'missing_earnings' => 'Completed, paid bookings without an earning in the provider ledger (often: commission or hold days not set).',
        'refund_not_in_ledger' => 'Settled refunds of a booking whose earning was recorded, with no ledger correction.',
        'negative_balances' => 'Providers whose available balance is below zero.',
        'pending_adjustments' => 'Ledger adjustments waiting for a second approver.',
    ],
];
