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
    'role_unchanged' => 'The role already has exactly these permissions.',
    'role_super_admin_fixed' => 'The super admin role always has every permission and cannot be edited.',
    'role_system' => 'System roles cannot be renamed or deleted.',
    'staff_same_role' => 'The staff member already has this role.',
    'staff_already_suspended' => 'This account is already suspended.',
    'staff_not_suspended' => 'This account is not suspended.',
    'staff_escalation' => 'You cannot grant permissions you do not hold, or manage super admins.',
    // Slice 12a (S12a).
    'DELETION_PENDING' => 'You already have an account deletion request under review.',
    // Slice 11 (payouts).
    'PAYOUT_ACCOUNT_REQUIRED' => 'Add your bank account before requesting a payout.',
    'PAYOUT_REQUEST_OPEN' => 'You already have a payout request in progress.',
    'INSUFFICIENT_BALANCE' => 'The amount is more than your available balance.',
];
