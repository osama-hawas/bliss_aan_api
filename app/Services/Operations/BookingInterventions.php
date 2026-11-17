<?php

namespace App\Services\Operations;

use App\Enums\ActivityVisibility;
use App\Enums\BookingStatus;
use App\Enums\CancellationReason;
use App\Enums\CancellationRequesterRole;
use App\Enums\CancellationStatus;
use App\Enums\ErrorCode;
use App\Enums\NotificationEvent;
use App\Enums\NotificationTargetType;
use App\Enums\ServiceRequestStatus;
use App\Exceptions\ApiException;
use App\Models\Booking;
use App\Models\CancellationRequest;
use App\Models\User;
use App\Services\Audit\ActivityRecorder;
use App\Services\Audit\AuditLogger;
use App\Services\Availability\DeadlockRetry;
use App\Services\Bookings\BookingExecution;
use App\Services\Bookings\BookingLocks;
use App\Services\Cancellations\CancellationReferenceGenerator;
use App\Services\Notifications\Notifier;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Staff interventions on a booking (slice-17-contract.md §5.2, D256). Lock order (Slice 6): request → booking → its
 * cancellation requests, inside DeadlockRetry. A staff cancellation is stored as an already accepted cancellation
 * request (`requester_role = staff`) so history, E12/E13-style reads and Slice 10's `cancellation_request_id` keep one
 * shape. No money moves (OD3): refunds go through Slice 10.
 */
class BookingInterventions
{
    private const MAX_REFERENCE_ATTEMPTS = 5;

    public function __construct(
        private readonly BookingLocks $locks,
        private readonly ActivityRecorder $activity,
        private readonly AuditLogger $audit,
        private readonly DeadlockRetry $retry,
        private readonly CancellationReferenceGenerator $references,
        private readonly Notifier $notifier,
        private readonly BookingExecution $execution,
    ) {}

    /**
     * `confirmed` or `in_progress` → `cancelled` (request too). A party's pending cancellation request must be decided
     * first (`409 CANCELLATION_PENDING`).
     */
    public function cancel(User $staff, Booking $booking, string $reason, ?string $internalNote): CancellationRequest
    {
        return $this->retry->run(fn () => DB::transaction(function () use ($staff, $booking, $reason, $internalNote) {
            [$request, $booking, $cancellations] = $this->locks->lock($booking);

            if (! in_array($booking->status, [BookingStatus::Confirmed, BookingStatus::InProgress], true)) {
                throw new ApiException(ErrorCode::InvalidState);
            }
            if ($cancellations->contains('status', CancellationStatus::UnderReview)) {
                throw new ApiException(ErrorCode::CancellationPending);
            }

            $now = now();
            $fromBooking = $booking->status;
            $cancellation = $this->insert([
                'booking_id' => $booking->id,
                'requester_id' => $staff->id,
                'requester_role' => CancellationRequesterRole::Staff,
                'reason_code' => CancellationReason::StaffDecision,
                'reason_text' => $reason,
                'status' => CancellationStatus::Accepted,
                'decided_by' => $staff->id,
                'decided_at' => $now,
                'decision_reason' => $reason,
                'internal_note' => $internalNote,
                'open_booking_id' => null,
            ]);

            $booking->update(['status' => BookingStatus::Cancelled, 'cancelled_at' => $now]);
            $from = $request->status;
            $request->update(['status' => ServiceRequestStatus::Cancelled, 'cancelled_at' => $now]);
            $this->activity->record($request, 'booking_cancelled_by_staff', $from, ServiceRequestStatus::Cancelled, $staff, ActivityVisibility::All,
                ['booking_id' => $booking->id, 'cancellation_request_id' => $cancellation->id, 'reason' => $reason]);
            if ($internalNote !== null && $internalNote !== '') {
                $this->activity->record($request, 'staff_note', null, null, $staff, ActivityVisibility::Internal, ['note' => $internalNote, 'booking_id' => $booking->id]);
            }
            $this->audit->log('booking.cancelled_by_staff', $staff, $booking, $reason,
                ['status' => $fromBooking->value], ['status' => BookingStatus::Cancelled->value, 'cancellation_request_id' => $cancellation->id]);

            // E23 to both parties (D257), after commit.
            $this->notifyBoth($booking, $request, NotificationEvent::BookingCancelledByStaff, 'booking_cancelled:'.$booking->id, $staff);

            return $cancellation;
        }), 'operations.booking_cancel', ['booking_id' => $booking->id]);
    }

    /**
     * `in_progress` → `completed` for a provider who did not confirm. Runs the Slice 6 completion (so later hooks in
     * `BookingExecution::complete`, e.g. the S11 ledger, run too) with the staff details.
     */
    public function complete(User $staff, Booking $booking, string $reason, ?string $internalNote): Booking
    {
        return $this->execution->complete($staff, $booking, ['reason' => $reason, 'internal_note' => $internalNote]);
    }

    /**
     * An internal note on the booking's request history. No notification.
     */
    public function addNote(User $staff, Booking $booking, string $note): void
    {
        DB::transaction(function () use ($staff, $booking, $note) {
            $request = $booking->serviceRequest()->firstOrFail();
            $this->activity->record($request, 'staff_note', null, null, $staff, ActivityVisibility::Internal, ['note' => $note, 'booking_id' => $booking->id]);
            $this->audit->log('booking.note_added', $staff, $booking, null, null, ['note' => $note]);
        });
    }

    /**
     * Notifies the client and the booked provider (staff are the actor, so nobody is dropped).
     */
    public function notifyBoth(Booking $booking, $request, NotificationEvent $event, string $dedupe, User $staff): void
    {
        $params = Notifier::bookingParams($booking, $request, null);
        $providerUserId = (int) $booking->provider()->value('user_id');
        foreach ([(int) $booking->client_id, $providerUserId] as $userId) {
            $this->notifier->send($userId, $event, $params, NotificationTargetType::Booking, $booking->id, $dedupe, $staff);
        }
    }

    private function insert(array $attributes): CancellationRequest
    {
        for ($attempt = 1; $attempt <= self::MAX_REFERENCE_ATTEMPTS; $attempt++) {
            try {
                return CancellationRequest::query()->create([...$attributes, 'reference' => $this->references->next()]);
            } catch (UniqueConstraintViolationException $e) {
                if (! str_contains($e->getMessage(), 'cancellation_requests_reference_unique')) {
                    throw $e;
                }
            }
        }

        Log::error('Cancellation reference generation exhausted its retries.', ['attempts' => self::MAX_REFERENCE_ATTEMPTS]);

        throw new ApiException(ErrorCode::ServerError);
    }
}
