<?php

// Default messages per App\Enums\ErrorCode.
return [
    'VALIDATION_FAILED' => 'The given data is invalid.',
    'UNAUTHENTICATED' => 'Please log in to continue.',
    'TOKEN_EXPIRED' => 'Your session has expired. Please refresh it.',
    'FORBIDDEN' => 'You are not allowed to perform this action.',
    'ACCOUNT_SUSPENDED' => 'Your account is suspended.',
    'NOT_FOUND' => 'The requested item was not found.',
    'ACCOUNT_NOT_FOUND' => 'No account is registered with this mobile number.',
    'METHOD_NOT_ALLOWED' => 'This action is not supported.',
    'INVALID_STATE' => 'This action is not available in the current state.',
    'NOT_CONFIGURED' => 'This feature is not configured yet.',
    'OTP_INVALID' => 'The verification code is incorrect.',
    'OTP_EXPIRED' => 'The verification code has expired. Please request a new one.',
    'TOO_MANY_ATTEMPTS' => 'Too many attempts. Please try again later.',
    'OUT_OF_COVERAGE' => 'This provider does not serve the selected city.',
    'NO_MATCHING_PROVIDERS' => 'No provider is available for this request in the selected city right now.',
    'DUPLICATE_REQUEST' => 'This request was already sent with different details.',
    'SLOT_UNAVAILABLE' => 'This provider is not available at the selected time. Please choose another date or time.',
    'BLOCK_OVERLAP' => 'This period overlaps a block you already have.',
    'OFFER_EXPIRED' => 'This offer has expired.',
    'ALREADY_BOOKED' => 'This request already has a confirmed booking.',
    'WALLET_EXISTS' => 'You already have a wallet.',
    'CANCELLATION_PENDING' => 'A cancellation request for this booking is already under review.',
    'START_TOO_EARLY' => 'It is too early to start this service.',
    'ALREADY_REVIEWED' => 'You have already reviewed this booking.',
    'ALREADY_REPORTED' => 'You have already reported this review.',
    'OUT_OF_STOCK' => 'This quantity is not available in stock.',
    'SERVER_ERROR' => 'Something went wrong. Please try again.',

    // Messages for specific INVALID_STATE cases.
    'application_documents_open' => 'Accept every attached document before activating the account.',
    'application_documents_missing' => 'A required document is missing.',

    // Slice 14b — staff & roles.
    'LAST_SUPER_ADMIN' => 'This would leave no active super admin.',
    'OWN_ACCOUNT' => 'You cannot change your own role or suspend your own account.',
    'ROLE_IN_USE' => 'This role is assigned to staff members and cannot be deleted.',
    'PERMISSION_RESTRICTED' => 'This permission is reserved for the super admin role.',
    // Slice 20 (S20)
    'TRANSLATION_MISSING' => 'The text must be filled in Arabic and English first.',
    'AUDIENCE_CHANGED' => 'The number of recipients changed. Check the audience again before sending.',
    'NO_RECIPIENTS' => 'No user matches this audience.',
    'role_unchanged' => 'The role already has exactly these permissions.',
    'role_super_admin_fixed' => 'The super admin role always has every permission and cannot be edited.',
    'role_system' => 'System roles cannot be renamed or deleted.',
    'staff_same_role' => 'The staff member already has this role.',
    'staff_already_suspended' => 'This account is already suspended.',
    'staff_not_suspended' => 'This account is not suspended.',
    'staff_escalation' => 'You cannot grant permissions you do not hold, or manage super admins.',
    // Slice 12a (S12a).
    'DELETION_PENDING' => 'You already have an account deletion request under review.',
    // Slice 10 (payments).
    'PAYMENT_REQUIRED' => 'This offer must be paid before the booking is confirmed.',
    'PAYMENT_NOT_REQUIRED' => 'This offer does not need a payment. Accept it directly.',
    'PAYMENT_METHOD_INVALID' => 'This payment method cannot be used. Check the card details or use another card.',
    'REFUND_AMOUNT_EXCEEDED' => 'The refund is more than the amount left to refund on this payment.',
    'INVALID_SIGNATURE' => 'The webhook signature is not valid.',
    'payment_not_refundable' => 'Only a successful payment can be refunded.',
    'payment_fulfilment_pending' => 'The booking for this payment is still being confirmed. Try the refund again in a minute.',
    // Slice 9b — store checkout and orders.
    'CART_EMPTY' => 'Your cart is empty.',
    'CART_HAS_ISSUES' => 'Some items in your cart are unavailable or exceed the available stock. Update your cart to continue.',
    'PROMO_CODE_INVALID' => 'This promo code cannot be used.',
    'PRICE_CHANGED' => 'The order total changed. Review the new total and confirm again.',
    'CARRIER_FAILED' => 'The carrier could not create the shipment. Try again or choose internal delivery.',
    // Slice 11 (payouts).
    'PAYOUT_ACCOUNT_REQUIRED' => 'Add your bank account before requesting a payout.',
    'PAYOUT_REQUEST_OPEN' => 'You already have a payout request in progress.',
    'INSUFFICIENT_BALANCE' => 'The amount is more than your available balance.',
    // Slice 18 (S18).
    'STOCK_CHANGED' => 'The stock changed since you opened it. Check the current count and try again.',
    // Slice 19 (S19).
    'ADJUSTMENT_LIMIT_EXCEEDED' => 'The amount is above the largest adjustment allowed.',
    'SELF_APPROVAL' => 'Another staff member must approve an adjustment you requested.',
    // S21 (D322).
    'DELETION_BLOCKED' => 'This account still has open obligations (bookings, orders, returns, payouts or tickets). It can be deleted once they are closed.',
    'EXPORT_TOO_LARGE' => 'This export has more rows than one file can hold. Narrow the period or filters and try again.',
    // S23 (D343).
    'RETURN_WINDOW_CLOSED' => 'The return period for this order has ended.',
];
