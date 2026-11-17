<?php

// Slice 9 store catalog and cart.
return [
    'attributes' => [
        'size' => 'Size',
        'color' => 'Colour',
    ],
    'out_of_stock' => 'Only the available stock can be added to the cart.',
    // Slice 9b.
    'max_quantity' => 'You can order at most :max of this item.',
    'promo' => [
        'unknown' => 'This promo code does not exist or is no longer active.',
        'not_started' => 'This promo code is not active yet.',
        'ended' => 'This promo code has expired.',
        'usage_limit' => 'This promo code has reached its usage limit.',
        'user_limit' => 'You have already used this promo code the maximum number of times.',
        'min_amount' => 'This promo code needs a products subtotal of at least :amount SAR.',
        'covers_order' => 'This promo code cannot cover the whole order.',
        'code_taken' => 'This code is already used by another promo code.',
        'locked_after_use' => 'The type and value cannot change after the code has been used.',
    ],
    'cancel_reason' => ['stock_unavailable' => 'An item ran out before your payment was confirmed. A full refund has started.', 'staff' => 'Cancelled by the store team.'],
    'reorder' => ['unavailable' => 'No longer available', 'out_of_stock' => 'Out of stock', 'reduced' => 'Quantity reduced to what is available'],
    'internal_requires_officer' => 'Choose the delivery officer for internal delivery.',
    'officer_invalid' => 'This staff member cannot take deliveries.',
    'shipment_failed_reason' => 'Write why the delivery failed.',
];
