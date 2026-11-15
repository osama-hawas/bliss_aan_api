<?php

namespace App\Services\Availability;

use App\Models\AvailabilityBlock;
use App\Models\Booking;
use App\Models\Provider;
use App\Models\ServiceRequestRecipient;
use App\Services\Settings\AppSettings;
use App\Support\RiyadhCalendar;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Read-side availability (Slice 5a): the exact overlap check (Req 17, `calendar_conflict`) and the full-day rule for
 * public dates (D92). Only active blocks count (D94). Takes no locks.
 *
 * Slice 5c (D108–D110): her `confirmed` / `in_progress` bookings make her busy too, widened by the platform buffer
 * `bookings.buffer_minutes` on both sides (`[starts_at − buffer, ends_at + buffer)`, default 0). "Busy" = a block or a
 * buffered booking overlaps.
 */
class ProviderAvailability
{
    public function __construct(private readonly AppSettings $settings) {}

    public function bufferMinutes(): int
    {
        return max(0, (int) ($this->settings->get('bookings.buffer_minutes') ?? 0));
    }

    /**
     * At least one of her busy bookings, widened by the buffer, overlaps [from, to) (D110).
     */
    public function isBooked(int $providerId, DateTimeInterface $from, DateTimeInterface $to): bool
    {
        $buffer = $this->bufferMinutes();

        return Booking::query()->where('provider_id', $providerId)->busy()
            ->where('starts_at', '<', Carbon::instance($to)->addMinutes($buffer))
            ->where('ends_at', '>', Carbon::instance($from)->subMinutes($buffer))
            ->exists();
    }

    /**
     * A block or a buffered booking overlaps [from, to): acceptance and direct-request creation answer 409
     * SLOT_UNAVAILABLE on it.
     */
    public function isBusy(int $providerId, DateTimeInterface $from, DateTimeInterface $to): bool
    {
        return $this->isBlocked($providerId, $from, $to) || $this->isBooked($providerId, $from, $to);
    }

    /**
     * Her busy bookings overlapping [from, to) as block-shaped intervals widened by the buffer (unsaved models).
     *
     * @param  list<int>|int  $providerIds
     * @return Collection<int, AvailabilityBlock>
     */
    private function bookingIntervals(array|int $providerIds, DateTimeInterface $from, DateTimeInterface $to): Collection
    {
        $buffer = $this->bufferMinutes();

        return Booking::query()->whereIn('provider_id', (array) $providerIds)->busy()
            ->where('starts_at', '<', Carbon::instance($to)->addMinutes($buffer))
            ->where('ends_at', '>', Carbon::instance($from)->subMinutes($buffer))
            ->get(['provider_id', 'starts_at', 'ends_at'])
            ->map(function (Booking $b) use ($buffer) {
                $interval = new AvailabilityBlock;
                $interval->provider_id = $b->provider_id;
                $interval->starts_at = $b->starts_at->copy()->subMinutes($buffer);
                $interval->ends_at = $b->ends_at->copy()->addMinutes($buffer);

                return $interval;
            });
    }

    /**
     * At least one of her active blocks overlaps [from, to) (§3.2).
     */
    public function isBlocked(int $providerId, DateTimeInterface $from, DateTimeInterface $to): bool
    {
        return AvailabilityBlock::query()->where('provider_id', $providerId)->overlapping($from, $to)->exists();
    }

    /**
     * `calendar_conflict` (§6): she is still taking part (`new` / `offer_sent`) and an active block overlaps the window.
     */
    public function hasConflict(ServiceRequestRecipient $recipient): bool
    {
        if (isset($recipient->calendar_conflict)) {
            return (bool) $recipient->calendar_conflict;
        }

        $r = $recipient->serviceRequest;

        return $recipient->status->isActive() && $r->status->isOpenForResponses()
            && $this->isBusy($recipient->provider_id, $r->starts_at, $r->ends_at);
    }

    /**
     * Pre-computes `calendar_conflict` for many rows of one provider with a single query.
     *
     * @param  iterable<ServiceRequestRecipient>  $recipients
     */
    public function fillConflicts(iterable $recipients): void
    {
        $recipients = collect($recipients);
        $active = $recipients->filter(fn (ServiceRequestRecipient $r) => $r->status->isActive());
        if ($active->isEmpty()) {
            $recipients->each(fn (ServiceRequestRecipient $r) => $r->setAttribute('calendar_conflict', false));

            return;
        }

        $from = $active->min(fn (ServiceRequestRecipient $r) => $r->serviceRequest->starts_at);
        $to = $active->max(fn (ServiceRequestRecipient $r) => $r->serviceRequest->ends_at);
        $providerIds = $active->pluck('provider_id')->unique()->values()->all();
        $blocks = AvailabilityBlock::query()
            ->whereIn('provider_id', $providerIds)
            ->overlapping($from, $to)
            ->get(['provider_id', 'starts_at', 'ends_at'])
            ->concat($this->bookingIntervals($providerIds, $from, $to));

        foreach ($recipients as $recipient) {
            $r = $recipient->serviceRequest;
            $recipient->setAttribute('calendar_conflict', $recipient->status->isActive() && $r->status->isOpenForResponses() && $blocks->contains(
                fn (AvailabilityBlock $b) => $b->provider_id === $recipient->provider_id && $b->starts_at->lt($r->ends_at) && $r->starts_at->lt($b->ends_at)
            ));
        }
    }

    /**
     * Riyadh dates in [from, to] that her active blocks and buffered bookings cover completely, alone or together
     * (D92, §7; bookings D110).
     *
     * @return list<string>
     */
    public function unavailableDates(Provider $provider, string $from, string $to): array
    {
        $rangeStart = RiyadhCalendar::dayStart($from);
        $rangeEnd = RiyadhCalendar::dayEnd($to);
        $blocks = AvailabilityBlock::query()
            ->where('provider_id', $provider->id)
            ->overlapping($rangeStart, $rangeEnd)
            ->get(['starts_at', 'ends_at'])
            ->concat($this->bookingIntervals($provider->id, $rangeStart, $rangeEnd))
            ->sortBy(fn (AvailabilityBlock $b) => $b->starts_at->getTimestamp())
            ->values();

        $dates = [];
        if ($blocks->isEmpty()) {
            return $dates;
        }

        for ($day = Carbon::createFromFormat('!Y-m-d', $from); $day->format('Y-m-d') <= $to; $day->addDay()) {
            $date = $day->format('Y-m-d');
            if ($this->coversDay($blocks, $date)) {
                $dates[] = $date;
            }
        }

        return $dates;
    }

    /**
     * Clip the overlapping blocks to the day, join touching or overlapping pieces, and require one piece from the
     * day start to the day end (§7 steps 1–4).
     *
     * @param  Collection<int, AvailabilityBlock>  $blocks  sorted by starts_at
     */
    public function coversDay(Collection $blocks, string $date): bool
    {
        $start = RiyadhCalendar::dayStart($date);
        $end = RiyadhCalendar::dayEnd($date);

        $pieces = $blocks
            ->filter(fn (AvailabilityBlock $b) => $b->starts_at->lt($end) && $start->lt($b->ends_at))
            ->map(fn (AvailabilityBlock $b) => [$b->starts_at->max($start), $b->ends_at->min($end)])
            ->sortBy(fn (array $p) => $p[0]->getTimestamp())
            ->values();

        $reached = $start->copy();
        foreach ($pieces as [$pieceStart, $pieceEnd]) {
            if ($pieceStart->gt($reached)) {
                return false; // a gap before this piece
            }
            if ($pieceEnd->gt($reached)) {
                $reached = $pieceEnd->copy();
            }
        }

        return $reached->gte($end);
    }
}
