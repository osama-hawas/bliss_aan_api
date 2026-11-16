<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // Bliss aan vendors (Q8 open). Only `fake` drivers exist so far.
    'sms' => [
        'driver' => env('SMS_DRIVER', 'fake'),
        // Dev only (D31): every OTP is this code while APP_ENV=local. Ignored in any other environment.
        'fixed_otp' => env('OTP_FIXED_CODE'),
        // Abuse protection on the OTP endpoints (technical limits, not the business values of Q10).
        // Applied before any account lookup, so a registered and an unregistered number behave alike.
        'rate_limits' => [
            'request_per_phone_per_hour' => (int) env('OTP_REQUESTS_PER_PHONE_PER_HOUR', 5),
            'request_per_ip_per_hour' => (int) env('OTP_REQUESTS_PER_IP_PER_HOUR', 20),
            'verify_per_ip_per_minute' => (int) env('OTP_VERIFY_PER_IP_PER_MINUTE', 10),
        ],
    ],

    'payments' => [
        'driver' => env('PAYMENT_DRIVER', 'fake'),
    ],

    'shipping' => [
        'driver' => env('SHIPPING_DRIVER', 'fake'), // carrier seam, Slice 9 (slice-9-contract.md)
    ],

    'push' => [
        'driver' => env('PUSH_DRIVER', 'fake'), // fake | fcm (D139)
        'fcm' => [
            // Path to the Firebase service-account JSON (secret, never committed) and the project id
            // (optional: defaults to the JSON's project_id). Provided by the owner, slice-8-contract.md §12.
            'credentials' => env('FCM_CREDENTIALS'),
            'project_id' => env('FCM_PROJECT_ID'),
        ],
    ],

];
