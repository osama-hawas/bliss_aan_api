<?php

namespace App\Integrations\Shipping;

/**
 * What a carrier needs to create a shipment. Recipient and address are snapshots taken by the caller.
 */
final readonly class ShipmentRequest
{
    /**
     * @param  array<string, mixed>  $address
     * @param  list<array{name: string, quantity: int}>  $items
     */
    public function __construct(
        public string $reference,
        public string $recipientName,
        public string $recipientPhone,
        public array $address,
        public array $items,
    ) {}
}
