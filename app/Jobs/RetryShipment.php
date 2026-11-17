<?php

namespace App\Jobs;

use App\Enums\ShipmentStatus;
use App\Models\Shipment;
use App\Services\Store\ShipmentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Asks the carrier again for a shipment whose answer was unknown, with the same request key (Slice 9b §8.2). The
 * service stops scheduling retries after ShipmentService::MAX_ATTEMPTS; staff can still retry by hand.
 */
class RetryShipment implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function __construct(public readonly int $shipmentId) {}

    public function handle(ShipmentService $shipments): void
    {
        $shipment = Shipment::query()->find($this->shipmentId);
        if ($shipment?->status === ShipmentStatus::Pending) {
            $shipments->retryCarrier($shipment, null);
        }
    }
}
