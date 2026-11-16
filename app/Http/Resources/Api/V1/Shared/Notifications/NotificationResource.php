<?php

namespace App\Http\Resources\Api\V1\Shared\Notifications;

use App\Enums\NotificationEvent;
use App\Http\Resources\Concerns\FormatsApiValues;
use App\Services\Notifications\NotificationRenderer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Notifications\DatabaseNotification;

/**
 * One row of her list (slice-8-contract.md §4): text in the request language (Accept-Language), target ids only.
 *
 * @mixin DatabaseNotification
 */
class NotificationResource extends JsonResource
{
    use FormatsApiValues;

    public function toArray(Request $request): array
    {
        $data = (array) $this->data;
        $event = NotificationEvent::tryFrom((string) ($data['event'] ?? $this->type));
        [$title, $body] = $event
            ? app(NotificationRenderer::class)->render($event, (array) ($data['params'] ?? []), app()->getLocale())
            : ['', ''];

        return [
            'id' => $this->id,
            'event' => $event?->value ?? $this->type,
            'title' => $title,
            'body' => $body,
            'target' => [
                'type' => $data['target']['type'] ?? null,
                'id' => $data['target']['id'] ?? null,
            ],
            'read_at' => $this->dateTime($this->read_at),
            'created_at' => $this->dateTime($this->created_at),
        ];
    }
}
