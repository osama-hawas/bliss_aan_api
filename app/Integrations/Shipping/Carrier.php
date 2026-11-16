<?php

namespace App\Integrations\Shipping;

/**
 * Shipping carrier seam (Slice 9). The carrier is undecided (Q8) and so is the delivery method (C6): only a fake exists,
 * and nothing creates shipments yet (no orders in this scope). Not registered in AppServiceProvider::DRIVERS until the
 * Slice 8 merge (`slice-9-contract.md` §6).
 */
interface Carrier
{
    /**
     * Ask the carrier to create a shipment. `$requestKey` is forwarded so a retry never creates a second shipment
     * (planned `shipments.request_key unique`).
     */
    public function createShipment(ShipmentRequest $shipment, string $requestKey): CarrierResult;

    /**
     * The carrier's current tracking status for a tracking number.
     */
    public function track(string $trackingNumber): CarrierResult;
}
