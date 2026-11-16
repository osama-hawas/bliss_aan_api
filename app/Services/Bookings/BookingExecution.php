<?php

namespace App\Services\Bookings;

use App\Enums\ActivityVisibility;
use App\Enums\BookingStatus;
use App\Enums\CancellationStatus;
use App\Enums\ErrorCode;
use App\Enums\ServiceRequestStatus;
use App\Exceptions\ApiException;
use App\Models\Booking;
use App\Models\User;
use App\Services\Audit\ActivityRecorder;
use App\Services\Availability\DeadlockRetry;
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

            return $booking;
        }), 'booking.start', ['booking_id' => $booking->id]);
    }

    /**
     * Idempotent (D125): an already completed booking answers unchanged, with no second transition.
     */
    public function complete(User $actor, Booking $booking): Booking
    {
        return $this->retry->run(fn () => DB::transaction(function () use ($actor, $booking) {
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
            $this->activity->record($request, 'booking_completed', $from, ServiceRequestStatus::Completed, $actor, ActivityVisibility::All, ['booking_id' => $booking->id]);

            return $booking;
        }), 'booking.complete', ['booking_id' => $booking->id]);
    }
}
