<?php

namespace App\Http\Resources\Api\V1\Admin\Payments;

use App\Http\Resources\Concerns\FormatsApiValues;
use App\Models\Refund;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A refund for the dashboard (slice-10-contract.md §10.2).
 *
 * @mixin Refund
 */
class AdminRefundResource extends JsonResource
{
    use FormatsApiValues;

    public function toArray(Request $request): array
    {
        $r = $this->resource;

        return [
            'id' => $r->id,
            'reference' => $r->reference,
            'kind' => $r->kind->value,
            'kind_label' => $r->kind->label(),
            'status' => $r->status->value,
            'status_label' => $r->status->label(),
            'amount' => $this->money($r->amount),
            'reason' => $r->reason,
            'failure_code' => $r->failure_code,
            'initiated_by' => $r->initiator ? ['id' => $r->initiator->id, 'name' => $r->initiator->name] : null,
            'payment' => $r->payment ? ['id' => $r->payment->id, 'reference' => $r->payment->reference] : ['id' => $r->payment_id, 'reference' => null],
            'cancellation' => $r->cancellationRequest ? ['id' => $r->cancellationRequest->id, 'reference' => $r->cancellationRequest->reference] : null,
            'processed_at' => $this->dateTime($r->processed_at),
            'created_at' => $this->dateTime($r->created_at),
        ];
    }
}
