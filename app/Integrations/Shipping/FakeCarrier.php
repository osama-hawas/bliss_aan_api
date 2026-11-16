<?php

namespace App\Integrations\Shipping;

use Illuminate\Support\Str;

/**
 * Accepts every shipment, except a reference ending in `-FAIL`, which fails. Same request key → same result.
 * Tracking reports `in_transit` for numbers it created and `unknown` otherwise. Local development and tests only.
 */
class FakeCarrier implements Carrier
{
    /** @var array<string, CarrierResult> */
    private array $byKey = [];

    /** @var array<string, true> */
    private array $created = [];

    public function createShipment(ShipmentRequest $shipment, string $requestKey): CarrierResult
    {
        return $this->byKey[$requestKey] ??= $this->create($shipment);
    }

    public function track(string $trackingNumber): CarrierResult
    {
        if (! isset($this->created[$trackingNumber])) {
            return new CarrierResult('unknown', $trackingNumber);
        }

        return new CarrierResult('accepted', $trackingNumber, $this->url($trackingNumber), 'in_transit');
    }

    private function create(ShipmentRequest $shipment): CarrierResult
    {
        if (str_ends_with($shipment->reference, '-FAIL')) {
            return new CarrierResult('failed', null, raw: ['reference' => $shipment->reference, 'error' => 'fake_failure']);
        }

        $number = 'FAKE'.Str::upper(Str::random(10));
        $this->created[$number] = true;

        return new CarrierResult('accepted', $number, $this->url($number), 'created', ['reference' => $shipment->reference]);
    }

    private function url(string $number): string
    {
        return "https://carrier.invalid/track/{$number}";
    }
}
