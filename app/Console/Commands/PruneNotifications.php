<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * D136: deletes notifications that are read and older than 180 days. Unread rows are never pruned.
 */
class PruneNotifications extends Command
{
    public const RETENTION_DAYS = 180;

    protected $signature = 'notifications:prune';

    protected $description = 'Delete read notifications older than 180 days';

    public function handle(): int
    {
        $cutoff = now()->subDays(self::RETENTION_DAYS);
        $total = 0;
        do {
            $deleted = DB::table('notifications')->whereNotNull('read_at')->where('created_at', '<', $cutoff)->limit(1000)->delete();
            $total += $deleted;
        } while ($deleted === 1000);
        $this->info("Pruned {$total} notification(s).");

        return self::SUCCESS;
    }
}
