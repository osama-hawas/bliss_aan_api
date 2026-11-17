<?php

namespace App\Services\Operations;

use App\Enums\CancellationStatus;
use App\Enums\TicketRelatedType;
use App\Enums\TicketStatus;
use App\Models\Booking;
use App\Models\SupportTicket;
use App\Support\RiyadhCalendar;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Dashboard booking lookups (`bookings.view`, slice-17-contract.md §5.1). `q` never authorizes anything (D72).
 */
class BookingAdminQueries
{
    public const SORTS = ['starts_at', '-starts_at', 'created_at', '-created_at'];

    private const LIST_WITH = ['serviceRequest.city', 'serviceRequest.service', 'serviceRequest.eventType', 'client', 'provider', 'service'];

    /**
     * @param  array<string, mixed>  $filters
     */
    public function list(array $filters, ?string $sort, int $perPage): LengthAwarePaginator
    {
        $sort ??= 'starts_at';

        return $this->base()
            ->with(self::LIST_WITH)
            ->when($filters['q'] ?? null, function (Builder $q, string $text) {
                $digits = ltrim(preg_replace('/\D+/', '', $text) ?? '', '0');
                $q->where(fn (Builder $w) => $w
                    ->where('reference', 'like', "%{$text}%")
                    ->orWhereHas('serviceRequest', fn (Builder $r) => $r->where('reference', 'like', "%{$text}%"))
                    ->orWhereHas('client', fn (Builder $c) => $c->where('name', 'like', "%{$text}%")
                        ->when($digits !== '', fn (Builder $p) => $p->orWhere('phone', 'like', "%{$digits}%")))
                    ->orWhereHas('provider', fn (Builder $p) => $p->where('display_name', 'like', "%{$text}%")));
            })
            ->when($filters['status'] ?? null, fn (Builder $q, array $s) => $q->whereIn('status', $s))
            ->when($filters['payment_status'] ?? null, fn (Builder $q, string $s) => $q->where('payment_status', $s))
            ->when(($filters['has_pending_cancellation'] ?? null) !== null, fn (Builder $q) => $filters['has_pending_cancellation']
                ? $q->whereHas('cancellationRequests', fn (Builder $c) => $c->where('status', CancellationStatus::UnderReview))
                : $q->whereDoesntHave('cancellationRequests', fn (Builder $c) => $c->where('status', CancellationStatus::UnderReview)))
            ->when($filters['provider_id'] ?? null, fn (Builder $q, int $id) => $q->where('provider_id', $id))
            ->when($filters['client_id'] ?? null, fn (Builder $q, int $id) => $q->where('client_id', $id))
            ->when($filters['city_id'] ?? null, fn (Builder $q, int $id) => $q->whereHas('serviceRequest', fn (Builder $r) => $r->where('city_id', $id)))
            ->when($filters['from'] ?? null, fn (Builder $q, string $d) => $q->where('starts_at', '>=', RiyadhCalendar::dayStart($d)))
            ->when($filters['to'] ?? null, fn (Builder $q, string $d) => $q->where('starts_at', '<', RiyadhCalendar::dayEnd($d)))
            ->orderBy(ltrim($sort, '-'), str_starts_with($sort, '-') ? 'desc' : 'asc')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function find(int $id): Booking
    {
        return $this->base()
            ->with([
                ...self::LIST_WITH, 'offer.items', 'startedBy', 'completedBy',
                'cancellationRequests.requester', 'cancellationRequests.decider', 'review.hiddenBy',
                'serviceRequest.history.actor',
            ])
            ->findOrFail($id);
    }

    /**
     * Tickets linked to the booking or to its request, newest first.
     *
     * @return Collection<int, SupportTicket>
     */
    public function tickets(Booking $booking): Collection
    {
        return SupportTicket::query()
            ->where(fn (Builder $q) => $q
                ->where(fn (Builder $b) => $b->where('related_type', TicketRelatedType::Booking)->where('related_id', $booking->id))
                ->orWhere(fn (Builder $r) => $r->where('related_type', TicketRelatedType::ServiceRequest)->where('related_id', $booking->service_request_id)))
            ->orderByDesc('id')
            ->get();
    }

    private function base(): Builder
    {
        $openTickets = SupportTicket::query()->selectRaw('COUNT(*)')
            ->where('status', '!=', TicketStatus::Closed)
            ->where(fn (Builder $q) => $q
                ->where(fn (Builder $b) => $b->where('related_type', TicketRelatedType::Booking)->whereColumn('related_id', 'bookings.id'))
                ->orWhere(fn (Builder $r) => $r->where('related_type', TicketRelatedType::ServiceRequest)->whereColumn('related_id', 'bookings.service_request_id')));

        return Booking::query()
            ->select('bookings.*')
            ->selectSub($openTickets, 'open_tickets_count')
            ->withExists(['cancellationRequests as has_pending_cancellation' => fn (Builder $c) => $c->where('status', CancellationStatus::UnderReview)]);
    }
}
