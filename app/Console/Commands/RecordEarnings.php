<?php

namespace App\Console\Commands;

use App\Services\Payouts\EarningsRecorder;
use App\Services\Payouts\RefundAdjustments;
use Illuminate\Console\Command;

/**
 * Back-fill (slice-11-contract.md §4.1/§4.2): earnings for paid, completed bookings that have none (completed while the
 * payout settings were not specified) and refund adjustments a lost listener run did not write. Idempotent.
 */
class RecordEarnings extends Command
{
    protected $signature = 'payouts:record-earnings';

    protected $description = 'Record missing provider earnings and refund adjustments';

    public function handle(EarningsRecorder $earnings, RefundAdjustments $adjustments): int
    {
        $written = $earnings->recordMissing();
        $adjusted = $adjustments->recordMissing();
        $this->info("Earnings recorded: {$written}. Refund adjustments recorded: {$adjusted}.");

        return self::SUCCESS;
    }
}
