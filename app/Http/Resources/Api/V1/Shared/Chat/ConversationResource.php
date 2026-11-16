<?php

namespace App\Http\Resources\Api\V1\Shared\Chat;

use App\Http\Resources\Concerns\FormatsApiValues;
use App\Http\Resources\Concerns\PublicMediaUrl;
use App\Models\Conversation;
use App\Services\Chat\ChatAccess;
use App\Services\Chat\ChatMessenger;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A conversation as one participant sees it (slice-7-contract.md §5). The client sees the provider's public card; the
 * provider sees the client's first name only (D77) — never a phone or address.
 *
 * @mixin Conversation
 */
class ConversationResource extends JsonResource
{
    use FormatsApiValues, PublicMediaUrl;

    public function toArray(Request $request): array
    {
        $c = $this->resource;
        $viewer = $request->user();
        $access = app(ChatAccess::class);
        $asClient = $access->isClient($viewer, $c);
        $status = $access->status($c);
        $r = $c->serviceRequest;
        $last = $c->lastMessage;
        $name = trim((string) $c->client?->name);

        return [
            'id' => $c->id,
            'status' => $status->value,
            'status_label' => $status->label(),
            'can_send' => $access->canSend($c),
            'service_request' => [
                'id' => $r->id,
                'reference' => $r->reference,
                'status' => $r->status->value,
                'status_label' => $r->status->label(),
                'service' => ['id' => $r->service_id, 'name' => $r->service?->name],
                'event_type' => ['id' => $r->event_type_id, 'name' => $r->eventType?->name],
                'event_date' => $r->event_date->format('Y-m-d'),
                'booking_id' => $r->booking?->provider_id === $c->provider_id ? $r->booking->id : null,
            ],
            ...($asClient ? ['provider' => [
                'id' => $c->provider_id,
                'display_name' => $c->provider?->display_name,
                'avatar_url' => $this->publicUrl($c->provider?->avatar),
                'is_verified' => (bool) $c->provider?->is_verified,
            ]] : ['client' => ['first_name' => $name === '' ? null : preg_split('/\s+/u', $name)[0]]]),
            'last_message' => $last ? [
                'id' => $last->id,
                'type' => $last->type->value,
                'body' => $last->body === null ? null : mb_substr($last->body, 0, 120),
                'is_mine' => $last->sender_id === $viewer->id,
                'created_at' => $this->dateTime($last->created_at),
            ] : null,
            'last_message_at' => $this->dateTime($c->last_message_at),
            'unread_count' => (int) ($c->unread_count ?? app(ChatMessenger::class)->unreadCount($c, $viewer)),
            'created_at' => $this->dateTime($c->created_at),
        ];
    }
}
