<?php

namespace App\Http\Resources\Api\V1\Admin\Support;

use App\Http\Resources\Concerns\FormatsApiValues;
use App\Models\Conversation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A conversation a ticket gives access to (D260) — no messages; reading them is logged (§7).
 *
 * @mixin Conversation
 */
class AdminConversationResource extends JsonResource
{
    use FormatsApiValues;

    public function toArray(Request $request): array
    {
        $c = $this->resource;

        return [
            'id' => $c->id,
            'request' => ['id' => $c->service_request_id, 'reference' => $c->serviceRequest?->reference],
            'client' => ['id' => $c->client_id, 'name' => $c->client?->name],
            'provider' => ['id' => $c->provider_id, 'display_name' => $c->provider?->display_name],
            'last_message_at' => $this->dateTime($c->last_message_at),
            'messages_count' => (int) ($c->messages_count ?? 0),
        ];
    }
}
