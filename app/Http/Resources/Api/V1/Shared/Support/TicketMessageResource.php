<?php

namespace App\Http\Resources\Api\V1\Shared\Support;

use App\Http\Resources\Concerns\FormatsApiValues;
use App\Models\TicketMessage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A ticket message as the ticket's owner sees it: her own messages carry her name, anything else is the support team
 * (D161).
 *
 * @mixin TicketMessage
 */
class TicketMessageResource extends JsonResource
{
    use FormatsApiValues;

    public function __construct(TicketMessage $resource, private readonly int $ownerId)
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        $fromStaff = $this->author_id !== $this->ownerId;

        return [
            'id' => $this->id,
            'from_staff' => $fromStaff,
            'author_name' => $fromStaff ? __('support.team_name') : $this->author?->name,
            'body' => $this->body,
            'created_at' => $this->dateTime($this->created_at),
        ];
    }
}
