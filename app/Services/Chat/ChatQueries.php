<?php

namespace App\Services\Chat;

use App\Enums\BookingStatus;
use App\Enums\ConversationStatus;
use App\Enums\RecipientStatus;
use App\Enums\ServiceRequestStatus;
use App\Models\Conversation;
use App\Models\ConversationRead;
use App\Models\Message;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Conversation lists and message pages (slice-7-contract.md §5). Reading never moves a read marker.
 */
class ChatQueries
{
    public const MAX_PAGE = 50;

    public function __construct(private readonly ChatAccess $access) {}

    /**
     * Newest activity first. `unread_count` comes from one sub-query; `status` filters the same rule as §3.
     */
    public function list(User $user, ?string $status, int $perPage): LengthAwarePaginator
    {
        return $this->access->scope($user)
            ->select('conversations.*')
            ->selectSub($this->unreadSub($user), 'unread_count')
            ->with([...ChatAccess::WITH, 'serviceRequest.recipients'])
            ->when($status === ConversationStatus::Active->value, fn (Builder $q) => $q->where(fn (Builder $w) => $this->active($w)))
            ->when($status === ConversationStatus::ReadOnly->value, fn (Builder $q) => $q->whereNot(fn (Builder $w) => $this->active($w)))
            ->orderByRaw('coalesce(conversations.last_message_at, conversations.created_at) desc')
            ->orderByDesc('conversations.id')
            ->paginate(min($perPage, self::MAX_PAGE));
    }

    /**
     * Newest first, `before` = an older-than cursor (a message id).
     *
     * @return array{0: Collection<int, Message>, 1: bool} the page and whether older messages exist
     */
    public function messages(Conversation $conversation, ?int $before, int $perPage): array
    {
        $perPage = max(1, min($perPage, self::MAX_PAGE));
        $rows = Message::query()->where('conversation_id', $conversation->id)
            ->when($before, fn (Builder $q, int $id) => $q->where('id', '<', $id))
            ->with(['media', 'offer.items', 'offer.provider'])
            ->orderByDesc('id')
            ->limit($perPage + 1)
            ->get();

        return [$rows->take($perPage)->values(), $rows->count() > $perPage];
    }

    /**
     * The other participant's read marker (for the `read` flag on her own messages).
     */
    public function otherMarker(Conversation $conversation, User $viewer): int
    {
        $other = $this->access->isClient($viewer, $conversation) ? $conversation->provider?->user_id : $conversation->client_id;

        return (int) ConversationRead::query()->where('conversation_id', $conversation->id)->where('user_id', $other)->value('last_read_message_id');
    }

    private function unreadSub(User $user): QueryBuilder
    {
        return DB::table('messages as m')->selectRaw('count(*)')
            ->whereColumn('m.conversation_id', 'conversations.id')
            ->where('m.sender_id', '!=', $user->id)
            ->whereRaw('m.id > coalesce((select r.last_read_message_id from conversation_reads r where r.conversation_id = conversations.id and r.user_id = ?), 0)', [$user->id]);
    }

    /**
     * §3 in SQL: booked with this provider (busy booking), or no booking, request open and her recipient row active.
     */
    private function active(Builder $q): void
    {
        $busy = array_map(fn (BookingStatus $s) => $s->value, BookingStatus::busy());
        $open = [ServiceRequestStatus::AwaitingOffers->value, ServiceRequestStatus::OffersReceived->value];
        $taking = [RecipientStatus::New->value, RecipientStatus::OfferSent->value];

        $q->whereExists(fn (QueryBuilder $b) => $b->from('bookings')
            ->whereColumn('bookings.service_request_id', 'conversations.service_request_id')
            ->whereColumn('bookings.provider_id', 'conversations.provider_id')
            ->whereIn('bookings.status', $busy))
            ->orWhere(fn (Builder $w) => $w
                ->whereNotExists(fn (QueryBuilder $b) => $b->from('bookings')->whereColumn('bookings.service_request_id', 'conversations.service_request_id'))
                ->whereExists(fn (QueryBuilder $b) => $b->from('service_requests')
                    ->whereColumn('service_requests.id', 'conversations.service_request_id')
                    ->whereIn('service_requests.status', $open))
                ->whereExists(fn (QueryBuilder $b) => $b->from('service_request_recipients')
                    ->whereColumn('service_request_recipients.service_request_id', 'conversations.service_request_id')
                    ->whereColumn('service_request_recipients.provider_id', 'conversations.provider_id')
                    ->whereIn('service_request_recipients.status', $taking)));
    }
}
