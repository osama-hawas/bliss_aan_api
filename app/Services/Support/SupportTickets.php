<?php

namespace App\Services\Support;

use App\Enums\ActivityVisibility;
use App\Enums\ErrorCode;
use App\Enums\TicketRelatedType;
use App\Enums\TicketStatus;
use App\Enums\TicketTopic;
use App\Enums\UserType;
use App\Exceptions\ApiException;
use App\Models\Booking;
use App\Models\ServiceRequest;
use App\Models\SupportTicket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Services\Audit\ActivityRecorder;
use App\Services\ServiceRequests\IdempotencyRace;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Support tickets for the mobile apps (slice-12b-contract.md, D157–D163). Every lookup is scoped to the signed-in user
 * first, so another user's ticket is a 404. Creating is idempotent by `Idempotency-Key` (D162): replay first, then
 * one transaction that locks her user row and re-checks the key before inserting. A reply is idempotent by
 * `client_uuid` and moves the status under the ticket row lock (D160).
 */
class SupportTickets
{
    private const MAX_REFERENCE_ATTEMPTS = 5;

    private const REFERENCE_INDEX = 'support_tickets_reference_unique';

    private const IDEMPOTENCY_INDEX = 'support_tickets_user_id_idempotency_key_unique';

    public function __construct(
        private readonly TicketReferenceGenerator $references,
        private readonly ActivityRecorder $activity,
    ) {}

    public function list(User $user, ?TicketStatus $status, int $perPage): LengthAwarePaginator
    {
        $page = SupportTicket::query()
            ->where('user_id', $user->id)
            ->when($status, fn ($q) => $q->where('status', $status))
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->paginate($perPage);

        $this->fillRelatedReferences($page->getCollection());

        return $page;
    }

    public function find(User $user, int $id): SupportTicket
    {
        $ticket = SupportTicket::query()->where('user_id', $user->id)->find($id)
            ?? throw new ApiException(ErrorCode::NotFound);

        $ticket->load('publicMessages.author');
        $this->fillRelatedReferences(collect([$ticket]));

        return $ticket;
    }

    /**
     * @param  array{topic: string, subject: string, message: string, related_type?: ?string, related_id?: ?int}  $data
     * @return array{0: SupportTicket, 1: bool} the ticket and whether it was created now (false = replay)
     */
    public function create(User $user, string $key, array $data): array
    {
        $data = $this->normalize($data);
        $fingerprint = hash('sha256', json_encode($data));

        if ($existing = $this->replay($user, $key, $fingerprint)) {
            return [$existing, false];
        }

        $related = $this->resolveRelated($user, $data['related_type'], $data['related_id']);

        try {
            return DB::transaction(function () use ($user, $key, $fingerprint, $data, $related) {
                // Same-key creates queue on her row, then the later ones replay what the first stored (the
                // ServiceRequestCreator pattern): no duplicate-key race on the insert.
                User::query()->whereKey($user->id)->lockForUpdate()->first();
                if ($existing = $this->replay($user, $key, $fingerprint)) {
                    return [$existing, false];
                }

                $ticket = $this->insert([
                    'user_id' => $user->id,
                    'topic' => TicketTopic::from($data['topic']),
                    'subject' => $data['subject'],
                    'related_type' => $related?->value,
                    'related_id' => $related ? $data['related_id'] : null,
                    'status' => TicketStatus::Open,
                    'last_message_at' => now(),
                    'idempotency_key' => $key,
                    'idempotency_fingerprint' => $fingerprint,
                ]);

                TicketMessage::query()->create([
                    'ticket_id' => $ticket->id,
                    'author_id' => $user->id,
                    'body' => $data['message'],
                ]);

                $this->activity->record($ticket, 'ticket_opened', null, TicketStatus::Open, $user, $this->visibility($user));

                return [$ticket, true];
            });
        } catch (IdempotencyRace) {
            // Last guard (unique user_id + idempotency_key): a same-key row committed outside the lock.
            return [$this->replay($user, $key, $fingerprint) ?? throw new ApiException(ErrorCode::ServerError), false];
        }
    }

    /**
     * Her reply (D160): refused on a closed ticket; `resolved → open`, `waiting → in_progress`.
     *
     * @param  array{body: string, client_uuid: string}  $data
     * @return array{0: TicketMessage, 1: bool} the message and whether it was created now (false = replay)
     */
    public function reply(User $user, int $ticketId, array $data): array
    {
        $body = trim($data['body']);
        $fingerprint = hash('sha256', json_encode([$ticketId, $body]));

        // Scoped first: a foreign or unknown ticket is a 404 even for a repeated client_uuid.
        SupportTicket::query()->where('user_id', $user->id)->whereKey($ticketId)->exists()
            || throw new ApiException(ErrorCode::NotFound);

        if ($existing = $this->replayMessage($user, $data['client_uuid'], $fingerprint)) {
            return [$existing, false];
        }

        try {
            $message = DB::transaction(function () use ($user, $ticketId, $body, $data, $fingerprint) {
                $ticket = SupportTicket::query()->where('user_id', $user->id)->whereKey($ticketId)->lockForUpdate()->first()
                    ?? throw new ApiException(ErrorCode::NotFound);

                if (! $ticket->status->acceptsReplies()) {
                    throw new ApiException(ErrorCode::InvalidState);
                }

                $message = TicketMessage::query()->create([
                    'ticket_id' => $ticket->id,
                    'author_id' => $user->id,
                    'body' => $body,
                    'client_uuid' => $data['client_uuid'],
                    'fingerprint' => $fingerprint,
                ]);

                $from = $ticket->status;
                $to = $from->afterUserReply();
                $ticket->forceFill(['status' => $to, 'last_message_at' => now()])->save();

                $this->activity->record($ticket, 'ticket_replied', $from, $to, $user, $this->visibility($user), ['message_id' => $message->id]);

                return $message;
            });
        } catch (UniqueConstraintViolationException) {
            // A parallel call with the same client_uuid committed first: answer like a replay.
            return [$this->replayMessage($user, $data['client_uuid'], $fingerprint) ?? throw new ApiException(ErrorCode::ServerError), false];
        }

        return [$message, true];
    }

    /**
     * Fills `relatedReference` (`RN-…` / `BK-…`) with one query per type.
     *
     * @param  Collection<int, SupportTicket>  $tickets
     */
    public function fillRelatedReferences(Collection $tickets): void
    {
        foreach (TicketRelatedType::cases() as $type) {
            $ids = $tickets->where('related_type', $type)->pluck('related_id')->unique()->values();
            if ($ids->isEmpty()) {
                continue;
            }
            $references = $this->relatedQuery($type)->whereIn('id', $ids)->pluck('reference', 'id');
            $tickets->where('related_type', $type)->each(fn (SupportTicket $t) => $t->relatedReference = $references[$t->related_id] ?? null);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{topic: string, subject: string, message: string, related_type: ?string, related_id: ?int}
     */
    private function normalize(array $data): array
    {
        $type = $data['related_type'] ?? null;

        return [
            'topic' => $data['topic'],
            'subject' => trim($data['subject']),
            'message' => trim($data['message']),
            'related_type' => $type,
            'related_id' => $type !== null && isset($data['related_id']) ? (int) $data['related_id'] : null,
        ];
    }

    /**
     * A related record must be hers (D159): a client's own requests and bookings; a provider's bookings and the
     * requests she received. Anything else is the same validation error, so existence never leaks.
     */
    private function resolveRelated(User $user, ?string $type, ?int $id): ?TicketRelatedType
    {
        if ($type === null) {
            return null;
        }

        $related = TicketRelatedType::from($type);
        $query = $this->relatedQuery($related)->whereKey($id);

        $owned = match ($user->type) {
            UserType::Client => (clone $query)->where('client_id', $user->id)->exists(),
            UserType::Provider => $this->ownedByProvider($user, $related, $query),
            default => false,
        };

        if (! $owned) {
            throw ValidationException::withMessages(['related_id' => __('support.related_invalid')]);
        }

        return $related;
    }

    private function ownedByProvider(User $user, TicketRelatedType $type, $query): bool
    {
        $providerId = $user->provider()->value('id');
        if ($providerId === null) {
            return false;
        }

        return match ($type) {
            TicketRelatedType::Booking => $query->where('provider_id', $providerId)->exists(),
            TicketRelatedType::ServiceRequest => $query->whereHas('recipients', fn ($q) => $q->where('provider_id', $providerId))->exists(),
        };
    }

    private function relatedQuery(TicketRelatedType $type)
    {
        return match ($type) {
            TicketRelatedType::ServiceRequest => ServiceRequest::query(),
            TicketRelatedType::Booking => Booking::query(),
        };
    }

    private function visibility(User $user): ActivityVisibility
    {
        return $user->type === UserType::Provider ? ActivityVisibility::Provider : ActivityVisibility::Client;
    }

    private function replay(User $user, string $key, string $fingerprint): ?SupportTicket
    {
        $existing = SupportTicket::query()->where('user_id', $user->id)->where('idempotency_key', $key)->first();
        if ($existing && ! hash_equals($existing->idempotency_fingerprint, $fingerprint)) {
            throw new ApiException(ErrorCode::DuplicateRequest);
        }

        return $existing;
    }

    private function replayMessage(User $user, string $uuid, string $fingerprint): ?TicketMessage
    {
        $existing = TicketMessage::query()->where('author_id', $user->id)->where('client_uuid', $uuid)->first();
        if ($existing && ! hash_equals((string) $existing->fingerprint, $fingerprint)) {
            throw new ApiException(ErrorCode::DuplicateRequest);
        }

        return $existing;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function insert(array $attributes): SupportTicket
    {
        for ($attempt = 1; $attempt <= self::MAX_REFERENCE_ATTEMPTS; $attempt++) {
            try {
                return SupportTicket::query()->create([...$attributes, 'reference' => $this->references->next()]);
            } catch (UniqueConstraintViolationException $e) {
                if (str_contains($e->getMessage(), self::IDEMPOTENCY_INDEX)) {
                    throw new IdempotencyRace;
                }
                if (! str_contains($e->getMessage(), self::REFERENCE_INDEX)) {
                    throw $e;
                }
            }
        }

        Log::error('Support ticket reference generation exhausted its retries.', ['attempts' => self::MAX_REFERENCE_ATTEMPTS]);

        throw new ApiException(ErrorCode::ServerError);
    }
}
