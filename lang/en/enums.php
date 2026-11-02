<?php

// Labels for App\Enums (key = enum short name in snake_case).
return [
    'user_type' => ['client' => 'Client', 'provider' => 'Provider', 'admin' => 'Admin'],
    'user_status' => ['active' => 'Active', 'suspended' => 'Suspended', 'pending_deletion' => 'Pending deletion'],
    'otp_purpose' => ['login' => 'Login', 'register' => 'Registration', 'change_phone' => 'Change mobile number'],
    'device_app' => ['client' => 'Client app', 'provider' => 'Provider app', 'dashboard' => 'Dashboard'],
    'media_visibility' => ['private' => 'Private', 'public' => 'Public'],
    'policy_type' => [
        'payment' => 'Payment policy',
        'cancellation' => 'Cancellation policy',
        'commission' => 'Commission policy',
        'store_return' => 'Store return policy',
        'terms' => 'Terms and conditions',
    ],
    'activity_visibility' => ['client' => 'Client', 'provider' => 'Provider', 'internal' => 'Internal', 'all' => 'Everyone'],
    'audience' => ['client' => 'Client app', 'provider' => 'Provider app'],
    'document_applies_to' => ['individual' => 'Individual', 'establishment' => 'Establishment', 'both' => 'Both'],
    'admin_role' => [
        'super_admin' => 'Super admin',
        'accounts_officer' => 'Accounts & onboarding officer',
        'operations_support' => 'Operations & support officer',
        'store_content_officer' => 'Store & content officer',
        'delivery_officer' => 'Delivery officer',
    ],
    'upload_context' => [
        'provider_document' => 'Provider document',
        'portfolio' => 'Portfolio',
        'chat_image' => 'Chat image',
        'chat_file' => 'Chat file',
        'chat_voice' => 'Voice note',
        'booking_update' => 'Booking update attachment',
        'catalog_image' => 'Catalog image',
        'banner' => 'Banner',
    ],
];
