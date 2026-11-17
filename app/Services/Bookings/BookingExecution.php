<?php

namespace App\Services\Bookings;

use App\Enums\ActivityVisibility;
use App\Enums\BookingStatus;
use App\Enums\CancellationStatus;
use App\Enums\ErrorCode;
use App\Enums\NotificationEvent;
use App\Enums\NotificationTargetType;
use App\Enums\ServiceRequestStatus;
use App\Exceptions\ApiException;
use App\Models\Booking;
use App\Models\User;
use App\Services\Audit\ActivityRecorder;
use App\Services\Audit\AuditLogger;
use App\Services\Availability\DeadlockRetry;
use App\Services\Notifications\Notifier;
use App\Services\Payouts\EarningsRecorder;
use App\Services\Settings\AppSettings;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Start and complete a booking (Req 21, D125, D126; slice-6-contract.md §3). The caller has already scoped the
 * booking to the provider. Every rule is re-checked on the locked rows.
 */
class BookingExecution
{
    public const DEFAULT_START_EARLY_MINUTES = 60;

    public function __construct(
        private readonly BookingLocks $locks,
        private readonly ActivityRecorder $activity,
        private readonly DeadlockRetry $retry,
        private readonly AppSettings $settings,
        private readonly Notifier $notifier,
        private readonly EarningsRecorder $earnings, // S11
    ) {}

    /**
     * The earliest moment the provider may start (D126): `starts_at` − the admin setting (default 60 minutes).
     */
    public function startAvailableAt(Booking $booking): CarbonInterface
    {
        $minutes = $this->settings->get('bookings.start_early_minutes');
        $minutes = is_numeric($minutes) ? max(0, (int) $minutes) : self::DEFAULT_START_EARLY_MINUTES;

        return $booking->starts_at->copy()->subMinutes($minutes);
    }

    public function start(User $actor, Booking $booking): Booking
    {
        return $this->retry->run(fn () => DB::transaction(function () use ($actor, $booking) {
            [$request, $booking, $cancellations] = $this->locks->lock($booking);

            if ($booking->status !== BookingStatus::Confirmed) {
                throw new ApiException(ErrorCode::InvalidState); // also a repeated start
            }
            if ($cancellations->contains('status', CancellationStatus::UnderReview)) {
                throw new ApiException(ErrorCode::CancellationPending);
            }
            if (now()->lt($this->startAvailableAt($booking))) {
                throw new ApiException(ErrorCode::StartTooEarly);
            }

            $booking->update(['status' => BookingStatus::InProgress, 'started_at' => now(), 'started_by' => $actor->id]);
            $from = $request->status;
            $request->update(['status' => ServiceRequestStatus::InProgress]);
            $this->activity->record($request, 'booking_started', $from, ServiceRequestStatus::InProgress, $actor, ActivityVisibility::All, ['booking_id' => $booking->id]);
            $this->notifyClient($booking, $request, NotificationEvent::BookingStarted, $actor); // E10

            return $booking;
        }), 'booking.start', ['booking_id' => $booking->id]);
    }

    /**
     * Idempotent (D125): an already completed booking answers unchanged, with no second transition.
     * Slice 17 (D256): `$staff` = `{reason, internal_note}` when staff complete it for the provider — the history entry,
     * audit row and E24 (both parties) replace the provider's E11 path.
     *
     * @param  array{reason: string, internal_note: ?string}|null  $staff
     */
    public function complete(User $actor, Booking $booking, ?array $staff = null): Booking
    {
        return $this->retry->run(fn () => DB::transaction(function () use ($actor, $booking, $staff) {
            [$request, $booking] = $this->locks->lock($booking);

            if ($booking->status === BookingStatus::Completed) {
                return $booking;
            }
            if ($booking->status !== BookingStatus::InProgress) {
                throw new ApiException(ErrorCode::InvalidState);
            }

            $booking->update(['status' => BookingStatus::Completed, 'completed_at' => now(), 'completed_by' => $actor->id]);
            $from = $request->status;
            $request->update(['status' => ServiceRequestStatus::Completed]);
            // --- S17 (Slice 17, D256/D257): staff completion ---
            if ($staff !== null) {
                $this->activity->record($request, 'booking_completed_by_staff', $from, ServiceRequestStatus::Completed, $actor, ActivityVisibility::All,
                    ['booking_id' => $booking->id, 'reason' => $staff['reason']]);
                if (($staff['internal_note'] ?? null) !== null && $staff['internal_note'] !== '') {
                    $this->activity->record($request, 'staff_note', null, null, $actor, ActivityVisibility::Internal,
                        ['note' => $staff['internal_note'], 'booking_id' => $booking->id]);
                }
                app(AuditLogger::class)->log('booking.completed_by_staff', $actor, $booking, $staff['reason'],
                    ['status' => BookingStatus::InProgress->value], ['status' => BookingStatus::Completed->value]);
                $params = Notifier::bookingParams($booking, $request, $booking->provider?->display_name);
                foreach ([(int) $booking->client_id, (int) $booking->provider()->value('user_id')] as $userId) { // E24
                    $this->notifier->send($userId, NotificationEvent::BookingCompletedByStaff, $params, NotificationTargetType::Booking,
                        $booking->id, 'booking_completed:'.$booking->id, $actor);
                }
            } else {
                // --- end S17 (no early return: later completion hooks run for both paths) ---
                $this->activity->record($request, 'booking_completed', $from, ServiceRequestStatus::Completed, $actor, ActivityVisibility::All, ['booking_id' => $booking->id]);
                $this->notifyClient($booking, $request, NotificationEvent::BookingCompleted, $actor); // E11
            }
            // S11 (slice-11-contract.md §4.1): the provider's earning for a paid booking; records nothing otherwise.
            // Runs for both the provider's and the staff completion (S17 D256).
            $this->earnings->recordLocked($booking);

            return $booking;
        }), 'booking.complete', ['booking_id' => $booking->id]);
    }

    private function notifyClient(Booking $booking, $request, NotificationEvent $event, User $actor): void
    {
        $this->notifier->send((int) $booking->client_id, $event, Notifier::bookingParams($booking, $request, $booking->provider?->display_name),
            NotificationTargetType::Booking, $booking->id, $event->value.':'.$booking->id, $actor);
    }
}
