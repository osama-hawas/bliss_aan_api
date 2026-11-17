<?php

namespace App\Console\Commands;

use App\Services\Account\AccountDeletionProcessor;
use Illuminate\Console\Command;

/**
 * S22 (OD38, D335): processes pending account deletion requests older than `account.deletion_grace_days`
 * (null = off). Accounts with open obligations are skipped and the reason is recorded on the request.
 */
class ProcessAccountDeletions extends Command
{
    protected $signature = 'accounts:process-deletions';

    protected $description = 'Delete (anonymise) accounts whose deletion request passed account.deletion_grace_days';

    public function handle(AccountDeletionProcessor $processor): int
    {
        $totals = $processor->processDue();
        $this->info(sprintf('Processed %d, blocked %d, skipped %d, failed %d.',
            $totals['processed'], $totals['blocked'], $totals['skipped'], $totals['failed']));

        return self::SUCCESS;
    }
}
