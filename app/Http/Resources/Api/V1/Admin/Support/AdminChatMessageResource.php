<?php

namespace App\Http\Resources\Api\V1\Admin\Support;

use App\Http\Resources\Concerns\FormatsApiValues;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Media\MediaService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A chat message as staff read it through a ticket (Req 42, D260): read-only, sender side and name, attachments as
 * 10-minute signed URLs (Slice 7 rule), the offer card as a reference only.
 *
 * @mixin Message
 */
class AdminChatMessageResource extends JsonResource
{
    use FormatsApiValues;

    public function __construct($resource, private readonly Conversation $conversation)
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        $m = $this->resource;
        $fromClient = (int) $m->sender_id === (int) $this->conversation->client_id;

        return [
            'id' => $m->id,
            'type' => $m->type->value,
            'body' => $m->body,
            'sender_side' => $fromClient ? 'client' : 'provider',
            'sender_name' => $fromClient ? $this->conversation->client?->name : $this->conversation->provider?->display_name,
            'attachment' => $m->media ? [
                'id' => $m->media->id,
                'name' => $m->media->original_name,
                'mime' => $m->media->mime,
                'size' => $m->media->size,
                'url' => app(MediaService::class)->url($m->media),
                'expires_at' => now()->addMinutes((int) config('filesystems.signed_url_minutes'))->utc()->toIso8601ZuluString(),
            ] : null,
            'offer' => $m->offer ? ['id' => $m->offer->id, 'reference' => $m->offer->reference, 'status' => $m->offer->effectiveStatus()->value] : null,
            'flagged' => (bool) $m->flagged,
            'created_at' => $this->dateTime($m->created_at),
        ];
    }
}
