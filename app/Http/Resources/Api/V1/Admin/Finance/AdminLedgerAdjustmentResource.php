<?php

namespace App\Http\Resources\Api\V1\Admin\Finance;

use App\Enums\LedgerAdjustmentStatus;
use App\Enums\Permission;
use App\Http\Resources\Concerns\FormatsApiValues;
use App\Http\Resources\Concerns\FormatsCents;
use App\Models\LedgerAdjustment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Dashboard ledger adjustment (slice-19-contract.md §6). `can_approve` / `can_reject` follow D287/D289 for the caller.
 *
 * @mixin LedgerAdjustment
 */
class AdminLedgerAdjustmentResource extends JsonResource
{
    use FormatsApiValues, FormatsCents;

    /** Set by the detail endpoint. */
    public mixed $history = null;

    public function toArray(Request $request): array
    {
        $a = $this->resource;
        $user = $request->user();
        $pending = $a->status === LedgerAdjustmentStatus::PendingApproval;
        $canProcess = $user?->can(Permission::FinanceProcess->value) ?? false;

        return [
            'id' => $a->id,
            'reference' => $a->reference,
            'provider' => ['id' => $a->provider_id, 'display_name' => $a->provider?->display_name],
            'direction' => $a->direction->value,
            'direction_label' => $a->direction->label(),
            'amount' => $this->cents($a->amount_cents),
            'signed_amount' => $this->cents($a->signedCents()),
            'reason_code' => $a->reason_code->value,
            'reason_code_label' => $a->reason_code->label(),
            'reason' => $a->reason,
            'booking' => $a->booking ? ['id' => $a->booking->id, 'reference' => $a->booking->reference] : null,
            'payout' => $a->payout_request_id ? ['id' => $a->payout_request_id, 'reference' => $a->payoutReference] : null,
            'status' => $a->status->value,
            'status_label' => $a->status->label(),
            'requires_approval' => $a->requires_approval,
            'limits' => $a->limits ? [
                'max_amount' => $this->money($a->limits['max_amount'] ?? null),
                'approval_amount' => $this->money($a->limits['approval_amount'] ?? null),
            ] : null,
            'requested_by' => ['id' => $a->requested_by, 'name' => $a->requester?->name],
            'decided_by' => $a->decided_by ? ['id' => $a->decided_by, 'name' => $a->decider?->name] : null,
            'decided_at' => $this->dateTime($a->decided_at),
            'decision_reason' => $a->decision_reason,
            'posted_at' => $this->dateTime($a->posted_at),
            'created_at' => $this->dateTime($a->created_at),
            'is_own' => $user !== null && $a->requested_by === $user->id,
            'can_approve' => $pending && $canProcess && $a->requested_by !== $user?->id,
            'can_reject' => $pending && $canProcess,
            'history' => $this->when($this->history !== null, fn () => collect($this->history)->map(fn ($e) => [
                'id' => $e->id,
                'event' => $e->event,
                'from_status' => $e->from_status,
                'to_status' => $e->to_status,
                'actor' => $e->actor ? ['id' => $e->actor->id, 'name' => $e->actor->name] : null,
                'created_at' => $this->dateTime($e->created_at),
            ])->values()),
        ];
    }
}
