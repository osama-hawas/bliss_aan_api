<?php

namespace App\Console\Commands;

use App\Services\Store\StoreOrderExpiry;
use Illuminate\Console\Command;

/**
 * Slice 9b (D212/Q115): unpaid store orders past the payment time with no open attempt become `expired`.
 */
class ExpireStoreOrders extends Command
{
    protected $signature = 'store-orders:expire';

    protected $description = 'Expire unpaid store orders whose payment time is over';

    public function handle(StoreOrderExpiry $expiry): int
    {
        $this->info('Expired '.$expiry->run().' store order(s).');

        return self::SUCCESS;
    }
}
