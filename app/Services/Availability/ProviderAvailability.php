<?php

namespace App\Services\Availability;

use App\Models\AvailabilityBlock;
use App\Models\Provider;
use App\Models\ServiceRequestRecipient;
use App\Support\RiyadhCalendar;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Read-side availability (Slice 5a): the exact overlap check (Req 17, `calendar_conflict`) and the full-day rule for
 * public dates (D92). Only active blocks count (D94). Takes no locks.
 */
class ProviderAvailability
{
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

        return $recipient->status->isActive() && $this->isBlocked($recipient->provider_id, $r->starts_at, $r->ends_at);
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
        $blocks = AvailabilityBlock::query()
            ->whereIn('provider_id', $active->pluck('provider_id')->unique())
            ->overlapping($from, $to)
            ->get(['provider_id', 'starts_at', 'ends_at']);

        foreach ($recipients as $recipient) {
            $r = $recipient->serviceRequest;
            $recipient->setAttribute('calendar_conflict', $recipient->status->isActive() && $blocks->contains(
                fn (AvailabilityBlock $b) => $b->provider_id === $recipient->provider_id && $b->starts_at->lt($r->ends_at) && $r->starts_at->lt($b->ends_at)
            ));
        }
    }

    /**
     * Riyadh dates in [from, to] that her active blocks cover completely, alone or together (D92, §7).
     *
     * @return list<string>
     */
    public function unavailableDates(Provider $provider, string $from, string $to): array
    {
        $blocks = AvailabilityBlock::query()
            ->where('provider_id', $provider->id)
            ->overlapping(RiyadhCalendar::dayStart($from), RiyadhCalendar::dayEnd($to))
            ->orderBy('starts_at')
            ->get(['starts_at', 'ends_at']);

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
