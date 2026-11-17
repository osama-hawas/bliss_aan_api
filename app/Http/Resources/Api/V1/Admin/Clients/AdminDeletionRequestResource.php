<?php

namespace App\Http\Resources\Api\V1\Admin\Clients;

use App\Enums\DeletionRequestStatus;
use App\Http\Resources\Concerns\FormatsApiValues;
use App\Models\AccountDeletionRequest;
use App\Services\Account\AccountDeletionProcessor;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A deletion request in the staff queue (S22, D336). `obligations` are live counts while pending (null once closed);
 * `blocked_by` is what the last processing attempt found.
 *
 * @mixin AccountDeletionRequest
 */
class AdminDeletionRequestResource extends JsonResource
{
    use FormatsApiValues;

    public function toArray(Request $request): array
    {
        $processor = app(AccountDeletionProcessor::class);
        $pending = $this->status === DeletionRequestStatus::Pending;
        $scheduledFor = $pending ? $processor->scheduledFor($this->resource) : null;
        $obligations = $pending ? $processor->obligations($this->user) : null;

        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'reason' => $this->reason_code->value,
            'reason_label' => $this->reason_code->label(),
            'details' => $this->details,
            'user_type' => $this->user_type->value,
            'user' => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'phone' => $this->user->phone,
                'status' => $this->user->status->value,
                'status_label' => $this->user->status->label(),
                'href' => $this->user_type->value === 'client' ? '/clients/'.$this->user->id : null,
            ],
            'requested_at' => $this->dateTime($this->created_at),
            'scheduled_for' => $this->dateTime($scheduledFor),
            'is_due' => $scheduledFor !== null && $scheduledFor->isPast(),
            'obligations' => $obligations === null ? null : (object) $obligations,
            'can_process' => $pending && $obligations === [],
            'blocked_by' => $this->blocked_by === null ? null : (object) $this->blocked_by,
            'checked_at' => $this->dateTime($this->checked_at),
            'withdrawn_at' => $this->dateTime($this->withdrawn_at),
            'processed_at' => $this->dateTime($this->processed_at),
            'processed_by' => $this->processor ? ['id' => $this->processor->id, 'name' => $this->processor->name] : null,
            'internal_note' => $this->internal_note,
        ];
    }
}
