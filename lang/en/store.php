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

    // Slice 18 (S18) — store management in the dashboard.
    'admin' => [
        'image_invalid' => 'Upload the image again: it must be a store image you uploaded and not used elsewhere.',
        'already_active' => 'This is already active.',
        'already_stopped' => 'This is already stopped.',
        'cannot_move' => 'It is already at the edge of the list.',
        'category_name_taken' => 'Another category already has this name.',
        'sku_taken' => 'This SKU is already used.',
        'variant_duplicate' => 'Another active option of this product has the same size and colour.',
        'note_required' => 'Add a note for this reason.',
        'stock_now' => 'The current stock is :stock.',
        'stock_unchanged' => 'The new stock is the same as the current stock.',
        'low_stock_not_configured' => 'The low-stock level is not set yet.',
        'messages' => [
            'category_created' => 'Category created.',
            'category_updated' => 'Category updated.',
            'category_stopped' => 'Category stopped. Its products are hidden from the app.',
            'category_activated' => 'Category activated.',
            'product_created' => 'Product created.',
            'product_updated' => 'Product updated.',
            'product_stopped' => 'Product stopped. It is hidden from the app.',
            'product_activated' => 'Product activated.',
            'variant_created' => 'Option added.',
            'variant_updated' => 'Option updated.',
            'variant_stopped' => 'Option stopped.',
            'variant_activated' => 'Option activated.',
            'stock_adjusted' => 'Stock updated.',
        ],
    ],
];
