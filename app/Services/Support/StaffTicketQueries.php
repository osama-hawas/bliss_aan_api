<?php

namespace App\Services\Support;

use App\Enums\TicketStatus;
use App\Models\ActivityEvent;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Dashboard ticket queue and detail (`support.view`, slice-17-contract.md §6). Staff see every ticket and every message,
 * internal notes included.
 */
class StaffTicketQueries
{
    public const SORTS = ['last_message_at', '-last_message_at', 'created_at', '-created_at'];

    public function __construct(private readonly SupportTickets $tickets) {}

    /**
     * @param  array<string, mixed>  $filters
     */
    public function list(User $staff, array $filters, ?string $sort, int $perPage): LengthAwarePaginator
    {
        $sort ??= '-last_message_at';

        $page = SupportTicket::query()
            ->with(['user', 'assignee'])
            ->addSelect(['last_message_author_id' => $this->lastAuthor()])
            ->when($filters['q'] ?? null, function (Builder $q, string $text) {
                $digits = ltrim(preg_replace('/\D+/', '', $text) ?? '', '0');
                $q->where(fn (Builder $w) => $w
                    ->where('reference', 'like', "%{$text}%")
                    ->orWhere('subject', 'like', "%{$text}%")
                    ->orWhereHas('user', fn (Builder $u) => $u->where('name', 'like', "%{$text}%")
                        ->when($digits !== '', fn (Builder $p) => $p->orWhere('phone', 'like', "%{$digits}%"))));
            })
            ->when($filters['status'] ?? null, fn (Builder $q, array $s) => $q->whereIn('status', $s))
            ->when($filters['topic'] ?? null, fn (Builder $q, string $t) => $q->where('topic', $t))
            ->when($filters['user_type'] ?? null, fn (Builder $q, string $t) => $q->whereHas('user', fn (Builder $u) => $u->where('type', $t)))
            ->when($filters['related_type'] ?? null, fn (Builder $q, string $t) => $q->where('related_type', $t))
            ->when($filters['assigned'] ?? null, fn (Builder $q, string $a) => match ($a) {
                'me' => $q->where('assigned_to', $staff->id),
                'none' => $q->whereNull('assigned_to'),
                'any' => $q->whereNotNull('assigned_to'),
                default => $q->where('assigned_to', (int) $a),
            })
            ->orderBy(ltrim($sort, '-'), str_starts_with($sort, '-') ? 'desc' : 'asc')
            ->orderByDesc('id')
            ->paginate($perPage);

        $this->tickets->fillRelatedReferences($page->getCollection());

        return $page;
    }

    public function find(int $id): SupportTicket
    {
        $ticket = SupportTicket::query()
            ->addSelect(['last_message_author_id' => $this->lastAuthor()])
            ->with(['user', 'assignee', 'messages' => fn ($q) => $q->with('author')->orderBy('id'), 'accessLogs' => fn ($q) => $q->with('staff')->orderByDesc('id')])
            ->findOrFail($id);
        $this->tickets->fillRelatedReferences(collect([$ticket]));

        return $ticket;
    }

    /**
     * Author of the latest non-internal message (for `last_message_from`), list and detail alike.
     */
    private function lastAuthor(): \Closure
    {
        return fn ($q) => $q->select('author_id')->from('ticket_messages')
            ->whereColumn('ticket_messages.ticket_id', 'support_tickets.id')->where('is_internal', false)
            ->orderByDesc('id')->limit(1);
    }

    /**
     * The ticket's own history (every visibility), oldest first.
     *
     * @return Collection<int, ActivityEvent>
     */
    public function history(SupportTicket $ticket): Collection
    {
        return ActivityEvent::query()->where('subject_type', $ticket->getMorphClass())->where('subject_id', $ticket->id)
            ->with('actor')->orderBy('id')->get();
    }

    /**
     * The staff moves allowed from the ticket's status (D258).
     *
     * @return list<TicketStatus>
     */
    public function transitions(SupportTicket $ticket): array
    {
        return StaffTickets::transitions($ticket->status);
    }
}
