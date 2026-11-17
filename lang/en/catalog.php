<?php

// Slice 16 (S16): admin catalog management messages.
return [
    'messages' => [
        'category_saved' => 'Category saved.',
        'category_stopped' => 'Category stopped. Its services are hidden from clients and providers.',
        'category_activated' => 'Category activated.',
        'service_saved' => 'Service saved.',
        'service_stopped' => 'Service stopped. Open requests and bookings continue unchanged.',
        'service_activated' => 'Service activated.',
        'addon_saved' => 'Add-on saved.',
        'addon_stopped' => 'Add-on stopped.',
        'addon_activated' => 'Add-on activated.',
        'event_type_saved' => 'Event type saved.',
        'event_type_stopped' => 'Event type stopped. It is hidden from new requests.',
        'event_type_activated' => 'Event type activated.',
        'moved' => 'Order saved.',
        // Slice 24 (S24): packages.
        'package_saved' => 'Package saved.',
        'package_stopped' => 'Package stopped. It is hidden from clients; requests made from it continue unchanged.',
        'package_activated' => 'Package activated.',
    ],
    'validation' => [
        'image' => 'Choose an image you uploaded for the catalog that is not used yet.',
        'reason_for_price' => 'Give a reason for changing the guide price.',
        'inclusion' => 'This inclusion does not belong to the service.',
        'inclusions_max' => 'A service can have at most :max inclusions.',
        'at_least_one' => 'Send at least one field to change.',
        'category' => 'Choose an existing category.',
        'addons' => 'Choose existing add-ons.',
        'services' => 'Choose existing services.',
    ],
];
