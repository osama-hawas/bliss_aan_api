<?php

namespace App\Http\Resources\Api\V1\Shared\Account;

use App\Enums\DeletionRequestStatus;
use App\Http\Resources\Concerns\FormatsApiValues;
use App\Models\AccountDeletionRequest;
use App\Services\Account\AccountDeletionProcessor;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Her own deletion request. Staff processing columns (who, internal note, blocked_by) are never shown (S22).
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
            // S22 (D330): when the scheduled run will delete the account (pending only; null = no automatic processing).
            'scheduled_for' => $this->dateTime($this->status === DeletionRequestStatus::Pending
                ? app(AccountDeletionProcessor::class)->scheduledFor($this->resource) : null),
        ];
    }
}
