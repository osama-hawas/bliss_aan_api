<?php

namespace App\Console\Commands;

use App\Services\Store\ShipmentService;
use Illuminate\Console\Command;

/**
 * Slice 9b (§8.3): polls the carrier for in-transit shipments (no carrier webhooks until a real vendor, OD2).
 */
class SyncShipments extends Command
{
    protected $signature = 'shipments:sync';

    protected $description = 'Fetch carrier tracking status for in-transit store shipments';

    public function handle(ShipmentService $shipments): int
    {
        $this->info('Moved '.$shipments->sync().' shipment(s).');

        return self::SUCCESS;
    }
}
