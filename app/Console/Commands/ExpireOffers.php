<?php

namespace App\Console\Commands;

use App\Enums\NotificationEvent;
use App\Enums\NotificationTargetType;
use App\Enums\OfferStatus;
use App\Models\Offer;
use App\Services\Notifications\Notifier;
use Illuminate\Console\Command;

/**
 * Stores `expired` on active offers past `valid_until` (slice-5b-contract.md §2). Reads never depend on it:
 * Offer::effectiveStatus() already presents them as expired. The request status is not changed (Q17 rule).
 * Slice 8: each offer this run actually moves to `expired` notifies its provider once (E7, D134).
 */
class ExpireOffers extends Command
{
    protected $signature = 'offers:expire';

    protected $description = 'Mark active offers past their validity as expired';

    public function handle(Notifier $notifier): int
    {
        $count = 0;
        $ids = Offer::query()->where('status', OfferStatus::Active)->where('valid_until', '<=', now())->orderBy('id')->pluck('id');
        foreach ($ids as $id) {
            // Conditional update: an offer accepted, withdrawn or closed meanwhile is left alone and not notified.
            $moved = Offer::query()->whereKey($id)->where('status', OfferStatus::Active)->where('valid_until', '<=', now())
                ->update(['status' => OfferStatus::Expired, 'updated_at' => now()]);
            if ($moved === 0) {
                continue;
            }
            $count++;
            $offer = Offer::query()->with(['provider:id,user_id', 'serviceRequest.service'])->find($id);
            if ($offer?->provider && $offer->serviceRequest) {
                $notifier->send((int) $offer->provider->user_id, NotificationEvent::OfferExpired, Notifier::requestParams($offer->serviceRequest, null),
                    NotificationTargetType::Request, $offer->service_request_id, 'offer_expired:'.$offer->id);
            }
        }
        $this->info("Expired {$count} offer(s).");

        return self::SUCCESS;
    }
}
