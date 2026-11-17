<?php

namespace Tests\Unit\Shipping;

use App\Integrations\Shipping\Carrier;
use App\Integrations\Shipping\FakeCarrier;
use App\Integrations\Shipping\ShipmentRequest;
use PHPUnit\Framework\TestCase;

class FakeCarrierTest extends TestCase
{
    private function shipment(string $reference = 'TEST-1'): ShipmentRequest
    {
        return new ShipmentRequest($reference, 'Client', '+966500000000', ['city' => 'Riyadh'], [['name' => 'Box', 'quantity' => 1]]);
    }

    public function test_it_implements_the_carrier_seam(): void
    {
        $this->assertInstanceOf(Carrier::class, new FakeCarrier);
    }

    public function test_create_is_idempotent_per_request_key(): void
    {
        $carrier = new FakeCarrier;

        $first = $carrier->createShipment($this->shipment(), 'key-1');
        $again = $carrier->createShipment($this->shipment(), 'key-1');
        $other = $carrier->createShipment($this->shipment(), 'key-2');

        $this->assertSame('accepted', $first->status);
        $this->assertNotNull($first->trackingNumber);
        $this->assertSame($first->trackingNumber, $again->trackingNumber);
        $this->assertNotSame($first->trackingNumber, $other->trackingNumber);
        $this->assertStringStartsWith('https://carrier.invalid/track/', $first->trackingUrl);
    }

    public function test_a_fail_reference_fails_without_a_tracking_number(): void
    {
        $result = (new FakeCarrier)->createShipment($this->shipment('TEST-1-FAIL'), 'key');

        $this->assertSame('failed', $result->status);
        $this->assertNull($result->trackingNumber);
    }

    public function test_tracking_knows_only_its_own_numbers(): void
    {
        $carrier = new FakeCarrier;
        $number = $carrier->createShipment($this->shipment(), 'key')->trackingNumber;

        $this->assertSame('in_transit', $carrier->track($number)->carrierStatus);
        $this->assertSame('unknown', $carrier->track('NOPE')->status);
    }

    public function test_s9b_tracking_maps_the_shipment_status(): void
    {
        $carrier = new FakeCarrier;
        $moving = $carrier->createShipment($this->shipment('SO-1'), 'k1');
        $done = $carrier->createShipment($this->shipment('SO-2-DELIVER'), 'k2');

        $this->assertSame('in_transit', $moving->shipmentStatus);
        $this->assertSame('in_transit', $carrier->track($moving->trackingNumber)->shipmentStatus);
        $this->assertSame('delivered', $carrier->track($done->trackingNumber)->shipmentStatus);
        $this->assertNull($carrier->track('UNKNOWN')->shipmentStatus);
    }
}
