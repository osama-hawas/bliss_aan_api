<?php

namespace App\Services\ServiceRequests;

use App\Enums\ActivityVisibility;
use App\Enums\ErrorCode;
use App\Enums\NotificationEvent;
use App\Enums\NotificationTargetType;
use App\Enums\OfferStatus;
use App\Enums\RecipientStatus;
use App\Enums\ServiceRequestStatus;
use App\Enums\ServiceRequestType;
use App\Exceptions\ApiException;
use App\Models\DeclineReason;
use App\Models\Offer;
use App\Models\Provider;
use App\Models\ServiceRequest;
use App\Models\ServiceRequestRecipient;
use App\Models\User;
use App\Services\Audit\ActivityRecorder;
use App\Services\Notifications\Notifier;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Slice 4 moves after creation (§3, §4): the client cancels; a recipient declines. Lock order everywhere: the
 * request row, then its recipient rows, then re-check the state (the current state wins). `event_passed` blocks
 * nothing (D62).
 */
class ServiceRequestLifecycle
{
    public function __construct(private readonly ActivityRecorder $activity, private readonly Notifier $notifier) {}

    /**
     * Before an offer is accepted only. Every recipient still taking part is closed, and every offer still `active`
     * (live or past its validity) is closed (Slice 5b). Reason optional (D67).
     */
    public function cancel(User $client, ServiceRequest $request, ?string $reason): ServiceRequest
    {
        return DB::transaction(function () use ($client, $request, $reason) {
            $request = $this->lock($request);
            $recipients = $this->lockRecipients($request);
            if (! $request->status->isOpenForResponses()) {
                throw new ApiException(ErrorCode::InvalidState);
            }

            $from = $request->status;
            $taking = $recipients->filter(fn (ServiceRequestRecipient $r) => $r->status->isActive())->pluck('provider_id');
            $request->update(['status' => ServiceRequestStatus::Cancelled, 'cancelled_at' => now(), 'cancel_reason' => $reason]);
            $recipients->filter(fn (ServiceRequestRecipient $r) => $r->status->isActive())
                ->each(fn (ServiceRequestRecipient $r) => $r->update(['status' => RecipientStatus::Closed, 'closed_at' => now()]));
            Offer::query()->where('service_request_id', $request->id)->whereIn('status', [OfferStatus::Active, OfferStatus::Expired])
                ->orderBy('id')->lockForUpdate()->get()
                ->each(fn (Offer $o) => $o->update(['status' => OfferStatus::Closed, 'closed_at' => now()]));
            $this->activity->record($request, 'request_cancelled', $from, ServiceRequestStatus::Cancelled, $client, ActivityVisibility::All);
            // E3 (D133): providers still taking part, after commit.
            $params = Notifier::requestParams($request, Notifier::firstName($client->name));
            foreach (Provider::query()->whereKey($taking->all())->orderBy('id')->pluck('user_id') as $userId) {
                $this->notifier->send((int) $userId, NotificationEvent::RequestCancelled, $params, NotificationTargetType::Request,
                    $request->id, 'request_cancelled:'.$request->id, $client);
            }

            return $request;
        });
    }

    /**
     * Her decline, with a predefined reason and a written explanation (D51), while she is `new` — or, after an offer,
     * while she is `offer_sent` and no offer is accepted: her active offer is withdrawn and she declines (D91,
     * slice-5b-contract.md §3.4). A direct request becomes `declined` (D68); an open one is unchanged, even when every
     * recipient declined or no live offer is left (Q17). Open-request entries stay internal (D76).
     */
    public function decline(Provider $provider, ServiceRequest $request, DeclineReason $reason, string $explanation): ServiceRequestRecipient
    {
        return DB::transaction(function () use ($provider, $request, $reason, $explanation) {
            $request = $this->lock($request);
            $recipient = ServiceRequestRecipient::query()
                ->where('service_request_id', $request->id)
                ->where('provider_id', $provider->id)
                ->lockForUpdate()
                ->firstOrFail();
            if (! $recipient->status->isActive() || ! $request->status->isOpenForResponses()) {
                throw new ApiException(ErrorCode::InvalidState);
            }

            $from = $recipient->status;
            $wasOfferSent = $from === RecipientStatus::OfferSent;
            $visibility = $request->type === ServiceRequestType::Direct ? ActivityVisibility::All : ActivityVisibility::Internal;
            if ($from === RecipientStatus::OfferSent) {
                Offer::query()->where('service_request_id', $request->id)->where('provider_id', $provider->id)
                    ->where('status', OfferStatus::Active)->lockForUpdate()->get()
                    ->each(function (Offer $o) use ($request, $provider, $visibility) {
                        $o->update(['status' => OfferStatus::Withdrawn, 'withdrawn_at' => now()]);
                        $this->activity->record($request, 'offer_withdrawn', OfferStatus::Active, OfferStatus::Withdrawn, $provider->user, $visibility, ['offer_id' => $o->id, 'provider_id' => $provider->id]);
                    });
            }

            $recipient->update([
                'status' => RecipientStatus::Declined,
                'decline_reason_id' => $reason->id,
                'decline_explanation' => $explanation,
                'declined_at' => now(),
            ]);
            $this->activity->record($request, 'recipient_declined', $from, RecipientStatus::Declined, $provider->user, ActivityVisibility::Internal, ['provider_id' => $provider->id, 'reason' => $reason->code]);

            if ($request->type === ServiceRequestType::Direct) {
                $from = $request->status;
                $request->update(['status' => ServiceRequestStatus::Declined]);
                $this->activity->record($request, 'request_declined', $from, ServiceRequestStatus::Declined, $provider->user, ActivityVisibility::All);
            }

            // E5/E6 (D133): only on direct requests, where the client sees the decline and the withdrawal (D68, D76).
            // Open-request withdrawals stay internal until Q63 is answered.
            if ($request->type === ServiceRequestType::Direct) {
                $this->notifier->send((int) $request->client_id, $wasOfferSent ? NotificationEvent::OfferWithdrawn : NotificationEvent::RequestDeclined,
                    Notifier::requestParams($request, $provider->display_name), NotificationTargetType::Request, $request->id,
                    'request_declined:'.$request->id, $provider->user_id);
            }

            return $recipient->setRelation('serviceRequest', $request);
        });
    }

    /**
     * The first time she opens the details.
     */
    public function markSeen(ServiceRequestRecipient $recipient): ServiceRequestRecipient
    {
        if ($recipient->seen_at === null) {
            ServiceRequestRecipient::query()->whereKey($recipient->id)->whereNull('seen_at')->update(['seen_at' => now()]);
            $recipient->refresh();
        }

        return $recipient;
    }

    private function lock(ServiceRequest $request): ServiceRequest
    {
        return ServiceRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
    }

    /**
     * @return Collection<int, ServiceRequestRecipient>
     */
    private function lockRecipients(ServiceRequest $request)
    {
        return ServiceRequestRecipient::query()->where('service_request_id', $request->id)->orderBy('id')->lockForUpdate()->get();
    }
}
