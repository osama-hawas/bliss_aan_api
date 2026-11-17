<?php

namespace App\Console\Commands;

use App\Services\Content\BroadcastCampaigns;
use Illuminate\Console\Command;

/**
 * Starts scheduled broadcast campaigns whose time has come (Slice 20, D305).
 */
class DispatchDueBroadcasts extends Command
{
    protected $signature = 'broadcasts:dispatch-due';

    protected $description = 'Start the scheduled broadcast campaigns that are due';

    public function handle(BroadcastCampaigns $campaigns): int
    {
        $this->info($campaigns->startDue().' campaign(s) started.');

        return self::SUCCESS;
    }
}
