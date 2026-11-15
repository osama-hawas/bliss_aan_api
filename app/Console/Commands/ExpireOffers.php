<?php

namespace App\Console\Commands;

use App\Enums\OfferStatus;
use App\Models\Offer;
use Illuminate\Console\Command;

/**
 * Stores `expired` on active offers past `valid_until` (slice-5b-contract.md §2). Reads never depend on it:
 * Offer::effectiveStatus() already presents them as expired. The request status is not changed (Q17 rule).
 */
class ExpireOffers extends Command
{
    protected $signature = 'offers:expire';

    protected $description = 'Mark active offers past their validity as expired';

    public function handle(): int
    {
        $count = Offer::query()->where('status', OfferStatus::Active)->where('valid_until', '<=', now())
            ->update(['status' => OfferStatus::Expired, 'updated_at' => now()]);
        $this->info("Expired {$count} offer(s).");

        return self::SUCCESS;
    }
}
