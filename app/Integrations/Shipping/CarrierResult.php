<?php

namespace App\Integrations\Shipping;

final readonly class CarrierResult
{
    /**
     * @param  string  $status  accepted | failed | unknown — the call outcome only. The carrier's own tracking status
     *                          is passed through raw in `$carrierStatus`; mapping it to order/shipment statuses is
     *                          deferred with the status transitions (`slice-9-contract.md` §7).
     */
    public function __construct(
        public string $status,
        public ?string $trackingNumber,
        public ?string $trackingUrl = null,
        public ?string $carrierStatus = null,
        public array $raw = [],
    ) {}
}
