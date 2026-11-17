<?php

namespace App\Services\Support;

use App\Enums\ErrorCode;
use App\Enums\TicketRelatedType;
use App\Enums\TicketStatus;
use App\Exceptions\ApiException;
use App\Models\Booking;
use App\Models\Conversation;
use App\Models\ConversationAccessLog;
use App\Models\Provider;
use App\Models\ServiceRequest;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Chat\ChatQueries;
use App\Services\Settings\AppSettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Staff read a chat only through a ticket (Req 42, slice-17-contract.md §7, D260): `chats.view_for_ticket` (route) and
 * the ticket's assignee; the ticket is not closed and links a request/booking; only that record's conversations in
 * which the ticket's user takes part. Every page read writes one `conversation_access_logs` row. Read-only: no send, no
 * read marker. The user is not told.
 */
class ChatReview
{
    public const DEFAULT_PER_PAGE = 30;

    public function __construct(
        private readonly ChatQueries $chat,
        private readonly AuditLogger $audit,
        private readonly AppSettings $settings,
    ) {}

    /**
     * @return Collection<int, Conversation>
     */
    public function conversations(User $staff, SupportTicket $ticket): Collection
    {
        $this->guard($staff, $ticket);

        return $this->scope($ticket)
            ->with(['serviceRequest', 'client', 'provider'])
            ->withCount('messages')
            ->orderBy('id')
            ->get();
    }

    /**
     * Whether the caller could review chats on this ticket (for the dashboard's `chat.can_review`).
     */
    public function canReview(User $staff, SupportTicket $ticket): bool
    {
        return (int) $ticket->assigned_to === $staff->id
            && $ticket->status !== TicketStatus::Closed
            && $ticket->related_type !== null
            && $staff->checkPermissionTo('chats.view_for_ticket');
    }

    public function availableCount(SupportTicket $ticket): int
    {
        return $ticket->related_type === null ? 0 : $this->scope($ticket)->count();
    }

    /**
     * A new review with its reason: logs, then returns the newest page (or the page before `$before`).
     *
     * @return array{0: ConversationAccessLog, 1: Collection, 2: bool, 3: Conversation}
     */
    public function open(User $staff, SupportTicket $ticket, int $conversationId, string $reason, ?int $before, int $perPage, ?string $ip): array
    {
        $this->guard($staff, $ticket);
        $conversation = $this->conversation($ticket, $conversationId);

        return $this->read($staff, $ticket, $conversation, $reason, $before, $perPage, $ip);
    }

    /**
     * A further page without a new reason, only for the same staff member, ticket and conversation, within
     * `support.chat_access_minutes` of the log row that **carried the reason** (the `/review` row; null setting = never).
     * The window never slides: any page row of the review may be passed, it resolves to its root. Still writes its own
     * log row, linked to that root.
     *
     * @return array{0: ConversationAccessLog, 1: Collection, 2: bool, 3: Conversation}
     */
    public function page(User $staff, SupportTicket $ticket, int $conversationId, int $accessLogId, ?int $before, int $perPage, ?string $ip): array
    {
        $this->guard($staff, $ticket);
        $conversation = $this->conversation($ticket, $conversationId);

        $previous = ConversationAccessLog::query()->whereKey($accessLogId)
            ->where('staff_id', $staff->id)->where('ticket_id', $ticket->id)->where('conversation_id', $conversation->id)->first();
        $root = $previous?->review_log_id ? ConversationAccessLog::query()->find($previous->review_log_id) : $previous;
        $minutes = $this->settings->get('support.chat_access_minutes');
        if (! $root || ! is_numeric($minutes) || (int) $minutes <= 0 || $root->created_at->lt(now()->subMinutes((int) $minutes))) {
            throw new ApiException(ErrorCode::InvalidState, __('support.access_expired'));
        }

        return $this->read($staff, $ticket, $conversation, $root->reason, $before, $perPage, $ip, $root->id);
    }

    /**
     * @return array{0: ConversationAccessLog, 1: Collection, 2: bool, 3: Conversation}
     */
    private function read(User $staff, SupportTicket $ticket, Conversation $conversation, string $reason, ?int $before, int $perPage, ?string $ip, ?int $rootId = null): array
    {
        [$messages, $hasMore] = $this->chat->messages($conversation, $before, $perPage);

        $log = DB::transaction(function () use ($staff, $ticket, $conversation, $reason, $before, $messages, $ip, $rootId) {
            $log = ConversationAccessLog::query()->create([
                'staff_id' => $staff->id,
                'ticket_id' => $ticket->id,
                'conversation_id' => $conversation->id,
                'review_log_id' => $rootId,
                'reason' => $reason,
                'messages_count' => $messages->count(),
                'before_message_id' => $before,
                'ip' => $ip,
            ]);
            $this->audit->log('chat.reviewed', $staff, $conversation, $reason, null,
                ['access_log_id' => $log->id, 'review_log_id' => $rootId, 'ticket_id' => $ticket->id, 'messages_count' => $messages->count()]);

            return $log;
        });

        return [$log, $messages, $hasMore, $conversation];
    }

    private function guard(User $staff, SupportTicket $ticket): void
    {
        if ((int) $ticket->assigned_to !== $staff->id) {
            throw new ApiException(ErrorCode::Forbidden);
        }
        if ($ticket->status === TicketStatus::Closed) {
            throw new ApiException(ErrorCode::InvalidState);
        }
    }

    private function conversation(SupportTicket $ticket, int $conversationId): Conversation
    {
        if ($ticket->related_type === null) {
            throw new ApiException(ErrorCode::NotFound);
        }

        return $this->scope($ticket)->with(['client', 'provider'])->whereKey($conversationId)->first()
            ?? throw new ApiException(ErrorCode::NotFound);
    }

    /**
     * Conversations of the linked record in which the ticket's user takes part. A booking narrows to its provider.
     */
    private function scope(SupportTicket $ticket): Builder
    {
        $query = Conversation::query();
        if ($ticket->related_type === null) {
            return $query->whereRaw('1 = 0');
        }

        if ($ticket->related_type === TicketRelatedType::Booking) {
            $booking = Booking::query()->find($ticket->related_id);
            if (! $booking) {
                return $query->whereRaw('1 = 0');
            }
            $query->where('service_request_id', $booking->service_request_id)->where('provider_id', $booking->provider_id);
        } else {
            ServiceRequest::query()->whereKey($ticket->related_id)->exists() || $query->whereRaw('1 = 0');
            $query->where('service_request_id', $ticket->related_id);
        }

        $providerIds = Provider::query()->where('user_id', $ticket->user_id)->pluck('id');

        return $query->where(fn (Builder $q) => $q->where('client_id', $ticket->user_id)->orWhereIn('provider_id', $providerIds));
    }
}
