<?php

namespace App\Services\Cancellations;

use App\Enums\ActivityVisibility;
use App\Enums\BookingStatus;
use App\Enums\CancellationReason;
use App\Enums\CancellationRequesterRole;
use App\Enums\CancellationStatus;
use App\Enums\ErrorCode;
use App\Enums\ServiceRequestStatus;
use App\Exceptions\ApiException;
use App\Models\Booking;
use App\Models\CancellationRequest;
use App\Models\User;
use App\Services\Audit\ActivityRecorder;
use App\Services\Audit\AuditLogger;
use App\Services\Availability\DeadlockRetry;
use App\Services\Bookings\BookingLocks;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Booking cancellation requests (Req 23, 24; D127, D128, D131; slice-6-contract.md §4). The booking stays fully
 * active while a request is under review. Locks: request → booking → its cancellation requests; then the rules are
 * re-checked. No money moves (D113).
 */
class BookingCancellations
{
    private const MAX_REFERENCE_ATTEMPTS = 5;

    public function __construct(
        private readonly BookingLocks $locks,
        private readonly ActivityRecorder $activity,
        private readonly AuditLogger $audit,
        private readonly DeadlockRetry $retry,
        private readonly CancellationReferenceGenerator $references,
    ) {}

    public function request(User $actor, CancellationRequesterRole $role, Booking $booking, CancellationReason $reason, string $text): CancellationRequest
    {
        if (! in_array($reason, CancellationReason::for($role), true)) {
            throw ValidationException::withMessages(['reason_code' => [__('validation.in', ['attribute' => 'reason_code'])]]);
        }

        return $this->retry->run(fn () => DB::transaction(function () use ($actor, $role, $booking, $reason, $text) {
            [$request, $booking, $cancellations] = $this->locks->lock($booking);

            if ($booking->status !== BookingStatus::Confirmed) {
                throw new ApiException(ErrorCode::InvalidState); // D127: only before the service starts
            }
            if ($cancellations->contains('status', CancellationStatus::UnderReview)) {
                throw new ApiException(ErrorCode::CancellationPending);
            }

            $cancellation = $this->insert([
                'booking_id' => $booking->id,
                'requester_id' => $actor->id,
                'requester_role' => $role,
                'reason_code' => $reason,
                'reason_text' => $text,
                'status' => CancellationStatus::UnderReview,
                'open_booking_id' => $booking->id,
            ]);
            $this->activity->record($request, 'cancellation_requested', null, CancellationStatus::UnderReview, $actor, ActivityVisibility::All,
                ['booking_id' => $booking->id, 'cancellation_request_id' => $cancellation->id, 'requester_role' => $role->value]);

            return $cancellation;
        }), 'cancellation.request', ['booking_id' => $booking->id]);
    }

    /**
     * Only the requester, only while under review. The caller scopes the lookup to her own requests.
     */
    public function withdraw(User $actor, CancellationRequest $cancellation): CancellationRequest
    {
        return $this->retry->run(fn () => DB::transaction(function () use ($actor, $cancellation) {
            [$request, , $cancellations] = $this->locks->lock($cancellation->booking);
            $cancellation = $cancellations->firstWhere('id', $cancellation->id) ?? throw new ApiException(ErrorCode::NotFound);

            if ($cancellation->requester_id !== $actor->id) {
                throw new ApiException(ErrorCode::NotFound);
            }
            if ($cancellation->status !== CancellationStatus::UnderReview) {
                throw new ApiException(ErrorCode::InvalidState);
            }

            $cancellation->update(['status' => CancellationStatus::Withdrawn, 'withdrawn_at' => now(), 'open_booking_id' => null]);
            $this->activity->record($request, 'cancellation_withdrawn', CancellationStatus::UnderReview, CancellationStatus::Withdrawn, $actor, ActivityVisibility::All,
                ['booking_id' => $cancellation->booking_id, 'cancellation_request_id' => $cancellation->id]);

            return $cancellation;
        }), 'cancellation.withdraw', ['cancellation_request_id' => $cancellation->id]);
    }

    /**
     * Admin approve/reject (D131). Accept cancels the booking and the request (D128): nothing is reopened.
     */
    public function decide(User $admin, CancellationRequest $cancellation, bool $accept, string $reason, ?string $note): CancellationRequest
    {
        return $this->retry->run(fn () => DB::transaction(function () use ($admin, $cancellation, $accept, $reason, $note) {
            [$request, $booking, $cancellations] = $this->locks->lock($cancellation->booking);
            $cancellation = $cancellations->firstWhere('id', $cancellation->id) ?? throw new ApiException(ErrorCode::NotFound);

            if ($cancellation->status !== CancellationStatus::UnderReview) {
                throw new ApiException(ErrorCode::InvalidState); // decided, withdrawn, or lost a concurrent decision
            }
            if ($accept && $booking->status !== BookingStatus::Confirmed) {
                throw new ApiException(ErrorCode::InvalidState); // cannot happen: start is blocked while pending
            }

            $now = now();
            $status = $accept ? CancellationStatus::Accepted : CancellationStatus::Rejected;
            $cancellation->update([
                'status' => $status, 'decided_by' => $admin->id, 'decided_at' => $now,
                'decision_reason' => $reason, 'internal_note' => $note, 'open_booking_id' => null,
            ]);
            $meta = ['booking_id' => $booking->id, 'cancellation_request_id' => $cancellation->id, 'requester_role' => $cancellation->requester_role->value];

            if ($accept) {
                $booking->update(['status' => BookingStatus::Cancelled, 'cancelled_at' => $now]);
                $from = $request->status;
                $request->update(['status' => ServiceRequestStatus::Cancelled, 'cancelled_at' => $now]);
                $this->activity->record($request, 'booking_cancelled', $from, ServiceRequestStatus::Cancelled, $admin, ActivityVisibility::All, $meta);
            } else {
                $this->activity->record($request, 'cancellation_rejected', CancellationStatus::UnderReview, CancellationStatus::Rejected, $admin, ActivityVisibility::All, $meta);
            }
            $this->audit->log($accept ? 'cancellation.accepted' : 'cancellation.rejected', $admin, $cancellation, $reason,
                ['status' => CancellationStatus::UnderReview->value, 'booking_status' => BookingStatus::Confirmed->value],
                ['status' => $status->value, 'booking_status' => $booking->status->value]);

            return $cancellation;
        }), 'cancellation.decide', ['cancellation_request_id' => $cancellation->id]);
    }

    private function insert(array $attributes): CancellationRequest
    {
        for ($attempt = 1; $attempt <= self::MAX_REFERENCE_ATTEMPTS; $attempt++) {
            try {
                return CancellationRequest::query()->create([...$attributes, 'reference' => $this->references->next()]);
            } catch (UniqueConstraintViolationException $e) {
                if (str_contains($e->getMessage(), 'open_booking_id_unique')) {
                    throw new ApiException(ErrorCode::CancellationPending);
                }
                if (! str_contains($e->getMessage(), 'cancellation_requests_reference_unique')) {
                    throw $e;
                }
            }
        }

        Log::error('Cancellation reference generation exhausted its retries.', ['attempts' => self::MAX_REFERENCE_ATTEMPTS]);

        throw new ApiException(ErrorCode::ServerError);
    }
}
