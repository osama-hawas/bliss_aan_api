<?php

namespace App\Http\Resources\Api\V1\Shared\Support;

use App\Http\Resources\Concerns\FormatsApiValues;
use App\Models\SupportTicket;
use App\Models\TicketMessage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Her ticket (slice-12b-contract.md §5). The detail adds `messages` and `can_reply` when the public messages are
 * loaded. Staff are shown only as the support team (D161); `assigned_to` and internal notes never appear.
 *
 * @mixin SupportTicket
 */
class SupportTicketResource extends JsonResource
{
    use FormatsApiValues;

    public function toArray(Request $request): array
    {
        $card = [
            'id' => $this->id,
            'reference' => $this->reference,
            'topic' => $this->topic->value,
            'topic_label' => $this->topic->label(),
            'subject' => $this->subject,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'related' => $this->related_type ? [
                'type' => $this->related_type->value,
                'id' => $this->related_id,
                'reference' => $this->resource->relatedReference,
            ] : null,
            'can_reply' => $this->status->acceptsReplies(),
            'last_message_at' => $this->dateTime($this->last_message_at),
            'created_at' => $this->dateTime($this->created_at),
        ];

        if ($this->relationLoaded('publicMessages')) {
            $card['messages'] = $this->publicMessages
                ->map(fn (TicketMessage $m) => (new TicketMessageResource($m, $this->user_id))->resolve($request))
                ->all();
        }

        return $card;
    }
}
