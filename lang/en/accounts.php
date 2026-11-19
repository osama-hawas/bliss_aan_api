<?php

// Slice 15: dashboard account history labels and messages (clients and providers).
return [
    'actions' => [
        'client_created' => 'Account added by staff',
        'client_suspended' => 'Account suspended',
        'client_reactivated' => 'Account reactivated',
        'client_updated' => 'Account details updated',
        'application_activated' => 'Account activated',
        'provider_suspended' => 'Provider suspended',
        'provider_reactivated' => 'Provider reactivated',
        'provider_verified' => 'Verification badge granted',
        'provider_unverified' => 'Verification badge removed',
        'provider_featured' => 'Added to featured providers',
        'provider_unfeatured' => 'Removed from featured providers',
        'portfolio_hidden' => 'Portfolio photo hidden',
        'portfolio_restored' => 'Portfolio photo restored',
    ],
    'messages' => [
        'client_created' => 'Client added. She signs in with OTP on that phone.',
        'provider_created' => 'Provider added as a draft application. She signs in with OTP, completes it and submits it for review.',
        'client_updated' => 'Client details saved.',
        'client_suspended' => 'Client suspended. She has been signed out of every device.',
        'client_reactivated' => 'Client reactivated. She must sign in again.',
        'provider_saved' => 'Provider saved.',
        'portfolio_hidden' => 'Photo hidden.',
        'portfolio_restored' => 'Photo restored.',
    ],
];
