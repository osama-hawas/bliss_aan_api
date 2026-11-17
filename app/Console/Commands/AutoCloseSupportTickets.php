<?php

namespace App\Console\Commands;

use App\Services\Support\StaffTickets;
use Illuminate\Console\Command;

/**
 * Closes resolved support tickets after `support.auto_close_days` (D265). The setting is null by default: no-op.
 */
class AutoCloseSupportTickets extends Command
{
    protected $signature = 'support:auto-close';

    protected $description = 'Close resolved support tickets older than support.auto_close_days (null = off)';

    public function handle(StaffTickets $tickets): int
    {
        $this->info('Closed '.$tickets->autoClose().' ticket(s).');

        return self::SUCCESS;
    }
}
