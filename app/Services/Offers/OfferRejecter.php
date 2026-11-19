<?php

namespace App\Services\Offers;

use App\Enums\ActivityVisibility;
use App\Enums\ErrorCode;
use App\Enums\NotificationEvent;
use App\Enums\NotificationTargetType;
use App\Enums\OfferStatus;
use App\Exceptions\ApiException;
use App\Models\Offer;
use App\Models\Provider;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Services\Audit\ActivityRecorder;
use App\Services\Notifications\Notifier;
use App\Services\ServiceRequests\RequestOfferStatus;
use Illuminate\Support\Facades\DB;

/**
 * The client rejects one live offer on her open request (QA BUG-012, owner decision 2026-11-19). The offer becomes
 * `rejected`; the provider stays a recipient (`offer_sent`) and may send a new offer. When no live offer is left the
 * request goes back to `awaiting_offers` (owner decision 2026-11-19, supersedes D105).
 */
class OfferRejecter
{
    public function __construct(
        private readonly ActivityRecorder $activity,
        private readonly Notifier $notifier,
        private readonly RequestOfferStatus $requestStatus,
    ) {}

    public function reject(User $client, Offer $offer, ?string $reason): Offer
    {
        return DB::transaction(function () use ($client, $offer, $reason) {
            $request = ServiceRequest::query()->whereKey($offer->service_request_id)->lockForUpdate()->firstOrFail();
            $offer = Offer::query()->whereKey($offer->id)->lockForUpdate()->firstOrFail();
            if ((int) $request->client_id !== (int) $client->id) {
                throw new ApiException(ErrorCode::Forbidden);
            }
            if (! $offer->isLive() || ! $request->status->isOpenForResponses()) {
                throw new ApiException(ErrorCode::InvalidState);
            }

            $offer->update(['status' => OfferStatus::Rejected, 'rejected_at' => now(), 'rejection_reason' => $reason]);
            $this->activity->record($request, 'offer_rejected', OfferStatus::Active, OfferStatus::Rejected, $client, ActivityVisibility::All,
                ['offer_id' => $offer->id, 'provider_id' => $offer->provider_id]);
            $this->requestStatus->backToAwaitingIfNoLiveOffer($request, $client);

            $providerUserId = (int) Provider::query()->whereKey($offer->provider_id)->value('user_id');
            $this->notifier->send($providerUserId, NotificationEvent::OfferRejected, Notifier::requestParams($request, null),
                NotificationTargetType::Request, $request->id, 'offer_rejected:'.$offer->id, $client->id);

            return $offer->refresh();
        });
    }
}
