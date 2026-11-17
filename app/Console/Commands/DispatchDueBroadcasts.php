<?php

namespace App\Console\Commands;

use App\Services\Content\BroadcastCampaigns;
use Illuminate\Console\Command;

/**
 * Starts scheduled broadcast campaigns whose time has come (Slice 20, D305) and re-dispatches campaigns stuck in
 * `sending` for longer than BroadcastCampaigns::STALE_SENDING_MINUTES (review M3).
 */
class DispatchDueBroadcasts extends Command
{
    protected $signature = 'broadcasts:dispatch-due';

    protected $description = 'Start the scheduled broadcast campaigns that are due and retry stuck ones';

    public function handle(BroadcastCampaigns $campaigns): int
    {
        $this->info($campaigns->startDue().' campaign(s) started.');
        $this->info($campaigns->redispatchStale().' stuck campaign(s) re-dispatched.');

        return self::SUCCESS;
    }
}
