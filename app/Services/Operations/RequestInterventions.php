<?php

namespace App\Services\Operations;

use App\Enums\ActivityVisibility;
use App\Enums\ErrorCode;
use App\Enums\NotificationEvent;
use App\Enums\NotificationTargetType;
use App\Enums\OfferStatus;
use App\Enums\RecipientStatus;
use App\Enums\ServiceRequestStatus;
use App\Exceptions\ApiException;
use App\Models\Offer;
use App\Models\Provider;
use App\Models\ServiceRequest;
use App\Models\ServiceRequestRecipient;
use App\Models\User;
use App\Services\Audit\ActivityRecorder;
use App\Services\Audit\AuditLogger;
use App\Services\Availability\DeadlockRetry;
use App\Services\Notifications\Notifier;
use Illuminate\Support\Facades\DB;

/**
 * Staff interventions on a service request (slice-17-contract.md §4, D256). Same lock order as the client cancel
 * (`ServiceRequestLifecycle`): request row → recipient rows → offers; the state is re-checked on the locked rows.
 */
class RequestInterventions
{
    public function __construct(
        private readonly ActivityRecorder $activity,
        private readonly AuditLogger $audit,
        private readonly DeadlockRetry $retry,
        private readonly Notifier $notifier,
    ) {}

    /**
     * Cancel an open request (`awaiting_offers` / `offers_received`). A booked request is cancelled through its booking.
     * The reason is shown to the client and to the providers still taking part.
     */
    public function cancel(User $staff, ServiceRequest $request, string $reason, ?string $internalNote): ServiceRequest
    {
        return $this->retry->run(fn () => DB::transaction(function () use ($staff, $request, $reason, $internalNote) {
            $request = ServiceRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            $recipients = ServiceRequestRecipient::query()->where('service_request_id', $request->id)->orderBy('id')->lockForUpdate()->get();
            if (! $request->status->isOpenForResponses()) {
                throw new ApiException(ErrorCode::InvalidState);
            }

            $now = now();
            $from = $request->status;
            $active = $recipients->filter(fn (ServiceRequestRecipient $r) => $r->status->isActive());
            $request->update(['status' => ServiceRequestStatus::Cancelled, 'cancelled_at' => $now, 'cancel_reason' => $reason]);
            $active->each(fn (ServiceRequestRecipient $r) => $r->update(['status' => RecipientStatus::Closed, 'closed_at' => $now]));
            Offer::query()->where('service_request_id', $request->id)->whereIn('status', [OfferStatus::Active, OfferStatus::Expired])
                ->orderBy('id')->lockForUpdate()->get()
                ->each(fn (Offer $o) => $o->update(['status' => OfferStatus::Closed, 'closed_at' => $now]));

            $this->activity->record($request, 'request_cancelled_by_staff', $from, ServiceRequestStatus::Cancelled, $staff,
                ActivityVisibility::All, ['reason' => $reason]);
            if ($internalNote !== null && $internalNote !== '') {
                $this->activity->record($request, 'staff_note', null, null, $staff, ActivityVisibility::Internal, ['note' => $internalNote]);
            }
            $this->audit->log('service_request.cancelled_by_staff', $staff, $request, $reason,
                ['status' => $from->value], ['status' => ServiceRequestStatus::Cancelled->value, 'internal_note' => $internalNote]);

            // E3 to the providers still taking part (unchanged event), E22 to the client (D257).
            $request->loadMissing('client', 'service');
            $params = Notifier::requestParams($request, Notifier::firstName($request->client?->name));
            foreach (Provider::query()->whereKey($active->pluck('provider_id')->all())->orderBy('id')->pluck('user_id') as $userId) {
                $this->notifier->send((int) $userId, NotificationEvent::RequestCancelled, $params, NotificationTargetType::Request,
                    $request->id, 'request_cancelled:'.$request->id, $staff);
            }
            $this->notifier->send((int) $request->client_id, NotificationEvent::RequestCancelledByStaff, $params, NotificationTargetType::Request,
                $request->id, 'request_cancelled:'.$request->id, $staff);

            return $request;
        }), 'operations.request_cancel', ['service_request_id' => $request->id]);
    }

    /**
     * An internal note on the request's history (D76 internal visibility). No notification.
     */
    public function addNote(User $staff, ServiceRequest $request, string $note): void
    {
        DB::transaction(function () use ($staff, $request, $note) {
            $this->activity->record($request, 'staff_note', null, null, $staff, ActivityVisibility::Internal, ['note' => $note]);
            $this->audit->log('service_request.note_added', $staff, $request, null, null, ['note' => $note]);
        });
    }
}
