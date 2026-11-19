<?php

namespace App\Services\ServiceRequests;

use App\Enums\ActivityVisibility;
use App\Enums\OfferStatus;
use App\Enums\ServiceRequestStatus;
use App\Models\Offer;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Services\Audit\ActivityRecorder;

/**
 * Owner decision 2026-11-19 (QA BUG-013, supersedes D105): a request in `offers_received` with no live offer left
 * (withdrawn or rejected) goes back to `awaiting_offers`, so the client never sees "Offers received" with zero offers.
 * Call inside the transaction that holds the request lock.
 */
class RequestOfferStatus
{
    public function __construct(private readonly ActivityRecorder $activity) {}

    public function backToAwaitingIfNoLiveOffer(ServiceRequest $request, ?User $actor): void
    {
        if ($request->status !== ServiceRequestStatus::OffersReceived) {
            return;
        }
        $live = Offer::query()->where('service_request_id', $request->id)
            ->where('status', OfferStatus::Active)->where('valid_until', '>', now())->exists();
        if ($live) {
            return;
        }
        $request->update(['status' => ServiceRequestStatus::AwaitingOffers]);
        $this->activity->record($request, 'request_awaiting_offers', ServiceRequestStatus::OffersReceived, ServiceRequestStatus::AwaitingOffers, $actor, ActivityVisibility::All);
    }
}
