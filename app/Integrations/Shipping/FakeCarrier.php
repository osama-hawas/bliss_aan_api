<?php

namespace App\Integrations\Shipping;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Str;

/**
 * Accepts every shipment, except a reference ending in `-FAIL`, which fails. Same request key → same result.
 * Tracking reports `in_transit` for numbers it created (`delivered` when the reference ends in `-DELIVER`) and
 * `unknown` otherwise. Local development and tests only. When the container builds it, its state is kept in the cache
 * store so the sync command (another process) sees the shipments a request created; `new FakeCarrier` keeps it in
 * memory.
 */
class FakeCarrier implements Carrier
{
    /** @var array<string, CarrierResult> */
    private array $byKey = [];

    /** @var array<string, string> tracking number => shipment reference */
    private array $created = [];

    public function __construct(private readonly ?Repository $cache = null) {}

    public function createShipment(ShipmentRequest $shipment, string $requestKey): CarrierResult
    {
        if ($known = $this->remembered('key:'.$requestKey)) {
            return $known;
        }

        $result = $this->create($shipment);
        $this->remember('key:'.$requestKey, $result);

        return $result;
    }

    public function track(string $trackingNumber): CarrierResult
    {
        $reference = $this->remembered('number:'.$trackingNumber);
        if ($reference === null) {
            return new CarrierResult('unknown', $trackingNumber);
        }

        $delivered = str_ends_with($reference, '-DELIVER');

        return new CarrierResult(
            'accepted',
            $trackingNumber,
            $this->url($trackingNumber),
            $delivered ? 'delivered' : 'in_transit',
            ['reference' => $reference],
            $delivered ? 'delivered' : 'in_transit',
        );
    }

    private function create(ShipmentRequest $shipment): CarrierResult
    {
        if (str_ends_with($shipment->reference, '-FAIL')) {
            return new CarrierResult('failed', null, raw: ['reference' => $shipment->reference, 'error' => 'fake_failure']);
        }

        $number = 'FAKE'.Str::upper(Str::random(10));
        $this->remember('number:'.$number, $shipment->reference);

        return new CarrierResult('accepted', $number, $this->url($number), 'created', ['reference' => $shipment->reference], 'in_transit');
    }

    private function remember(string $key, mixed $value): void
    {
        if ($this->cache !== null) {
            $this->cache->put('fake_carrier:'.$key, $value, now()->addDay());

            return;
        }
        str_starts_with($key, 'key:') ? $this->byKey[$key] = $value : $this->created[$key] = $value;
    }

    private function remembered(string $key): mixed
    {
        if ($this->cache !== null) {
            return $this->cache->get('fake_carrier:'.$key);
        }

        return str_starts_with($key, 'key:') ? ($this->byKey[$key] ?? null) : ($this->created[$key] ?? null);
    }

    private function url(string $number): string
    {
        return "https://carrier.invalid/track/{$number}";
    }
}
