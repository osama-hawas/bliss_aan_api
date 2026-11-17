<?php

namespace App\Http\Resources\Api\V1\Shared\Chat;

use App\Enums\OfferStatus;
use App\Http\Resources\Concerns\DescribesOffer;
use App\Http\Resources\Concerns\FormatsApiValues;
use App\Models\Message;
use App\Models\Offer;
use App\Services\Media\MediaService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One message for a participant (slice-7-contract.md §5–§6). Attachments get 10-minute signed URLs (the caller already
 * scoped the conversation to her). The offer card is rendered from the live offer; the client never sees a superseded
 * or withdrawn offer's details (5b §3.6). `flagged` is shown to the sender only (D253).
 *
 * @mixin Message
 */
class MessageResource extends JsonResource
{
    use DescribesOffer, FormatsApiValues;

    public function __construct($resource, private readonly int $otherMarker = 0, private readonly bool $viewerIsClient = false)
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        $m = $this->resource;
        $mine = $m->sender_id === $request->user()->id;
        $clientSent = $this->viewerIsClient ? $mine : ! $mine;

        return [
            'id' => $m->id,
            'type' => $m->type->value,
            'body' => $m->body,
            'client_uuid' => $mine ? $m->client_uuid : null,
            'is_mine' => $mine,
            'sender_side' => $clientSent ? 'client' : 'provider',
            'attachment' => $m->media ? [
                'id' => $m->media->id,
                'name' => $m->media->original_name,
                'mime' => $m->media->mime,
                'size' => $m->media->size,
                'url' => app(MediaService::class)->url($m->media),
                'expires_at' => now()->addMinutes((int) config('filesystems.signed_url_minutes'))->utc()->toIso8601ZuluString(),
            ] : null,
            'offer' => $m->offer ? $this->card($m->offer) : null,
            'read' => $mine ? $m->id <= $this->otherMarker : null,
            // D246/D253: only the sender learns her message was flagged for contact details.
            'flagged' => $mine ? (bool) $m->flagged : null,
            'created_at' => $this->dateTime($m->created_at),
        ];
    }

    private function card(Offer $offer): array
    {
        $status = $offer->effectiveStatus();
        if ($this->viewerIsClient && ! in_array($status->value, OfferStatus::clientVisible(), true)) {
            return ['id' => $offer->id, 'status' => $status->value, 'status_label' => $status->label(), 'available' => false];
        }

        return [
            ...$this->offerCore($offer),
            'service_request_id' => $offer->service_request_id,
            'provider' => ['id' => $offer->provider_id, 'display_name' => $offer->provider?->display_name],
            'available' => true,
        ];
    }
}
