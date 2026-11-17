<?php

namespace App\Http\Resources\Api\V1\Shared\Account;

use App\Http\Resources\Concerns\FormatsApiValues;
use App\Models\AccountDeletionRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Her own deletion request. Processing columns are never shown (D155: nothing writes them).
 *
 * @mixin AccountDeletionRequest
 */
class DeletionRequestResource extends JsonResource
{
    use FormatsApiValues;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'reason' => $this->reason_code->value,
            'reason_label' => $this->reason_code->label(),
            'details' => $this->details,
            'requested_at' => $this->dateTime($this->created_at),
            'withdrawn_at' => $this->dateTime($this->withdrawn_at),
        ];
    }
}
