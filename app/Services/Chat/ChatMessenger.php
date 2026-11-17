<?php

namespace App\Services\Chat;

use App\Enums\ErrorCode;
use App\Enums\MessageType;
use App\Enums\NotificationEvent;
use App\Enums\NotificationTargetType;
use App\Enums\OfferStatus;
use App\Enums\UserType;
use App\Exceptions\ApiException;
use App\Models\Conversation;
use App\Models\ConversationRead;
use App\Models\Media;
use App\Models\Message;
use App\Models\Offer;
use App\Models\User;
use App\Services\Media\MediaService;
use App\Services\Notifications\Notifier;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Sending and reading (slice-7-contract.md §4, §7). A send is deduplicated by (sender, client_uuid) and serialized per
 * conversation by its row lock; the conversation's last message moves in the same transaction. A body with contact
 * details (phone, email, URL) is stored `flagged` (D246) and still sent.
 */
class ChatMessenger
{
    public function __construct(private readonly ChatAccess $access, private readonly MediaService $media, private readonly Notifier $notifier, private readonly ContactInfoDetector $detector) {}

    /**
     * @param  array{client_uuid: string, type: string, body?: ?string, media_id?: ?int, offer_id?: ?int}  $data
     * @return array{0: Message, 1: bool} the message and whether it was created now (false = replay)
     */
    public function send(User $sender, Conversation $conversation, array $data): array
    {
        $type = MessageType::from($data['type']);
        $body = isset($data['body']) && trim((string) $data['body']) !== '' ? trim((string) $data['body']) : null;
        $mediaId = isset($data['media_id']) ? (int) $data['media_id'] : null;
        $offerId = isset($data['offer_id']) ? (int) $data['offer_id'] : null;
        $fingerprint = hash('sha256', json_encode([$conversation->id, $type->value, $body, $mediaId, $offerId]));

        if ($existing = $this->replay($sender, $data['client_uuid'], $fingerprint)) {
            return [$existing, false];
        }

        try {
            return [DB::transaction(function () use ($sender, $conversation, $type, $body, $mediaId, $offerId, $data, $fingerprint) {
                $conversation = Conversation::query()->whereKey($conversation->id)->lockForUpdate()->firstOrFail();
                if (Message::query()->where('sender_id', $sender->id)->where('client_uuid', $data['client_uuid'])->exists()) {
                    throw new DuplicateSend;
                }
                $conversation->load(['serviceRequest.booking', 'provider']);
                if (! $this->access->canSend($conversation)) {
                    throw new ApiException(ErrorCode::InvalidState);
                }

                $media = $type->uploadContext() ? $this->lockMedia($sender, $mediaId, $type) : null;
                if ($type === MessageType::OfferCard) {
                    $this->checkOffer($sender, $conversation, $offerId);
                }

                $message = Message::query()->create([
                    'conversation_id' => $conversation->id,
                    'sender_id' => $sender->id,
                    'type' => $type,
                    'body' => $body,
                    'media_id' => $media?->id,
                    'offer_id' => $type === MessageType::OfferCard ? $offerId : null,
                    'client_uuid' => $data['client_uuid'],
                    'fingerprint' => $fingerprint,
                    // D246 (S5): contact details are flagged, never blocked.
                    'flagged' => $this->detector->flags($body),
                ]);
                if ($media) {
                    $this->media->attach($media, $message);
                }
                $conversation->update(['last_message_id' => $message->id, 'last_message_at' => $message->created_at]);
                // Her own message is read by her.
                $this->moveMarker($conversation, $sender, $message->id);
                // E16 (D142): push only to the other participant, after commit; no text in the push (D141).
                $fromProvider = (int) $sender->id !== (int) $conversation->client_id;
                $this->notifier->send(
                    $fromProvider ? (int) $conversation->client_id : (int) $conversation->provider->user_id,
                    NotificationEvent::ChatMessage,
                    ['name' => $fromProvider ? $conversation->provider->display_name : Notifier::firstName($sender->name)],
                    NotificationTargetType::Conversation, $conversation->id, 'chat_message:'.$message->id, $sender,
                );

                return $message;
            }), true];
        } catch (DuplicateSend|UniqueConstraintViolationException) {
            return [$this->replay($sender, $data['client_uuid'], $fingerprint) ?? throw new ApiException(ErrorCode::ServerError), false];
        }
    }

    /**
     * Moves her read marker forward only (§7). The message must belong to the conversation.
     *
     * @return array{last_read_message_id: int, unread_count: int}
     */
    public function markRead(User $reader, Conversation $conversation, int $messageId): array
    {
        if (! Message::query()->where('conversation_id', $conversation->id)->whereKey($messageId)->exists()) {
            throw ValidationException::withMessages(['message_id' => __('validation.exists', ['attribute' => 'message id'])]);
        }

        $marker = DB::transaction(fn () => $this->moveMarker($conversation, $reader, $messageId));

        return ['last_read_message_id' => $marker, 'unread_count' => $this->unreadCount($conversation, $reader)];
    }

    public function unreadCount(Conversation $conversation, User $reader): int
    {
        $marker = (int) ConversationRead::query()->where('conversation_id', $conversation->id)->where('user_id', $reader->id)->value('last_read_message_id');

        return Message::query()->where('conversation_id', $conversation->id)->where('sender_id', '!=', $reader->id)->where('id', '>', $marker)->count();
    }

    private function moveMarker(Conversation $conversation, User $reader, int $messageId): int
    {
        $read = ConversationRead::query()->where('conversation_id', $conversation->id)->where('user_id', $reader->id)->lockForUpdate()->first();
        if (! $read) {
            try {
                $read = ConversationRead::query()->create(['conversation_id' => $conversation->id, 'user_id' => $reader->id, 'last_read_message_id' => 0]);
            } catch (UniqueConstraintViolationException) {
                $read = ConversationRead::query()->where('conversation_id', $conversation->id)->where('user_id', $reader->id)->lockForUpdate()->firstOrFail();
            }
        }
        if ($messageId > $read->last_read_message_id) {
            $read->update(['last_read_message_id' => $messageId]);
        }

        return $read->last_read_message_id;
    }

    private function lockMedia(User $sender, ?int $mediaId, MessageType $type): Media
    {
        $media = $mediaId === null ? null : Media::query()->whereKey($mediaId)
            ->where('uploaded_by', $sender->id)
            ->where('collection', $type->uploadContext()->value)
            ->whereNull('attachable_id')
            ->lockForUpdate()->first();

        return $media ?? throw ValidationException::withMessages(['media_id' => __('validation.custom.media_id.unavailable')]);
    }

    private function checkOffer(User $sender, Conversation $conversation, ?int $offerId): void
    {
        $ok = $sender->type === UserType::Provider && $offerId !== null && Offer::query()->whereKey($offerId)
            ->where('service_request_id', $conversation->service_request_id)
            ->where('provider_id', $conversation->provider_id)
            ->whereIn('status', OfferStatus::clientVisible())
            ->exists();
        if (! $ok) {
            throw ValidationException::withMessages(['offer_id' => __('validation.custom.offer_id.unavailable')]);
        }
    }

    private function replay(User $sender, string $uuid, string $fingerprint): ?Message
    {
        $existing = Message::query()->where('sender_id', $sender->id)->where('client_uuid', $uuid)->first();
        if ($existing && ! hash_equals($existing->fingerprint, $fingerprint)) {
            throw new ApiException(ErrorCode::DuplicateRequest);
        }

        return $existing;
    }
}
