<?php

namespace App\Services\Team;

use App\Enums\ActivityVisibility;
use App\Enums\BookingStatus;
use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Models\Booking;
use App\Models\BookingAssignment;
use App\Models\ProviderTeamMember;
use App\Models\User;
use App\Services\Audit\ActivityRecorder;
use App\Services\Availability\DeadlockRetry;
use App\Services\Bookings\BookingLocks;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Sets the team of one of her bookings (Slice 13, docs/slice-13-contract.md §4). Informational and provider only
 * (D230): the history goes on the booking with visibility `provider`, never on the request, and nobody is notified.
 * The caller has already scoped the booking to her provider.
 */
class BookingTeam
{
    public function __construct(
        private readonly BookingLocks $locks,
        private readonly ActivityRecorder $activity,
        private readonly DeadlockRetry $retry,
    ) {}

    /**
     * Replaces the booking's team with exactly `$memberIds` (PUT semantics; a repeat changes nothing).
     *
     * @param  list<int>  $memberIds
     */
    public function set(User $actor, Booking $booking, array $memberIds): Booking
    {
        return $this->retry->run(fn () => DB::transaction(function () use ($actor, $booking, $memberIds) {
            // Lock order as everywhere (BookingLocks): request → booking → its cancellation requests; then members.
            [, $booking] = $this->locks->lock($booking);

            if (! in_array($booking->status, BookingStatus::busy(), true)) {
                throw new ApiException(ErrorCode::InvalidState); // D232: completed / cancelled keep their team read-only
            }

            $members = ProviderTeamMember::query()
                ->where('provider_id', $booking->provider_id)
                ->whereIn('id', $memberIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $current = BookingAssignment::query()->where('booking_id', $booking->id)->pluck('team_member_id')->all();

            $errors = [];
            foreach (array_values($memberIds) as $index => $id) {
                $member = $members->get($id);
                if ($member === null) {
                    $errors["member_ids.{$index}"] = __('validation.exists', ['attribute' => __('validation.attributes.member_ids')]);
                } elseif (! $member->isActive() && ! in_array($id, $current, true)) {
                    $errors["member_ids.{$index}"] = __('validation.custom.member_ids.disabled'); // D233
                }
            }
            if ($errors) {
                throw ValidationException::withMessages($errors);
            }

            $added = array_values(array_diff($memberIds, $current));
            $removed = array_values(array_diff($current, $memberIds));

            foreach ($added as $id) {
                BookingAssignment::query()->create(['booking_id' => $booking->id, 'team_member_id' => $id, 'assigned_by' => $actor->id]);
                $this->record($booking, 'team_member_assigned', $actor, $members->get($id));
            }
            if ($removed) {
                $gone = ProviderTeamMember::query()->whereIn('id', $removed)->get()->keyBy('id');
                BookingAssignment::query()->where('booking_id', $booking->id)->whereIn('team_member_id', $removed)->delete();
                foreach ($removed as $id) {
                    $this->record($booking, 'team_member_unassigned', $actor, $gone->get($id));
                }
            }

            return $booking;
        }), 'booking.team', ['booking_id' => $booking->id]);
    }

    private function record(Booking $booking, string $event, User $actor, ?ProviderTeamMember $member): void
    {
        $this->activity->record($booking, $event, actor: $actor, visibility: ActivityVisibility::Provider,
            meta: ['team_member_id' => $member?->id, 'name' => $member?->name]);
    }
}
