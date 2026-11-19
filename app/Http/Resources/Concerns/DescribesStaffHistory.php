<?php

namespace App\Http\Resources\Concerns;

use App\Models\ActivityEvent;
use App\Models\DeclineReason;
use Illuminate\Support\Collection;

/**
 * Dashboard history entries (Slice 17): every visibility, with the staff actor and the note/reason kept in `meta`.
 * Admin resources only. Uses FormatsApiValues.
 */
trait DescribesStaffHistory
{
    /**
     * @param  Collection<int, ActivityEvent>  $events
     */
    protected function staffHistory(Collection $events): array
    {
        // BUG-019: a decline stores the reason's code in meta; show its label, not `fully_booked`.
        $codes = $events->filter(fn (ActivityEvent $e) => $e->event === 'recipient_declined' && isset($e->meta['reason']))
            ->map(fn (ActivityEvent $e) => $e->meta['reason'])->unique()->values()->all();
        $labels = $codes === [] ? collect() : DeclineReason::query()->whereIn('code', $codes)->get()->mapWithKeys(fn (DeclineReason $r) => [$r->code => $r->name]);

        return $events->map(fn (ActivityEvent $e) => [
            'id' => $e->id,
            'event' => $e->event,
            'event_label' => __('activity.'.$e->event),
            'from_status' => $e->from_status,
            'to_status' => $e->to_status,
            'visibility' => $e->visibility->value,
            'actor' => $e->actor_id ? ['id' => $e->actor_id, 'name' => $e->actor?->name, 'role' => $e->actor_role] : null,
            'note' => $e->meta['note'] ?? (isset($e->meta['reason']) ? ($labels[$e->meta['reason']] ?? $e->meta['reason']) : null),
            'at' => $this->dateTime($e->created_at),
        ])->values()->all();
    }

    /**
     * Internal staff notes only (`staff_note`), newest first.
     *
     * @param  Collection<int, ActivityEvent>  $events
     */
    protected function staffNotes(Collection $events): array
    {
        return $events->where('event', 'staff_note')->sortByDesc('id')->map(fn (ActivityEvent $e) => [
            'id' => $e->id,
            'note' => $e->meta['note'] ?? null,
            'author' => $e->actor_id ? ['id' => $e->actor_id, 'name' => $e->actor?->name] : null,
            'booking_id' => $e->meta['booking_id'] ?? null,
            'at' => $this->dateTime($e->created_at),
        ])->values()->all();
    }
}
