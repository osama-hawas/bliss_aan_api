<?php

namespace App\Http\Resources\Api\V1\Admin\Payouts;

use App\Enums\PayoutRequestStatus;
use App\Enums\Permission;
use App\Models\ActivityEvent;
use App\Models\PayoutRequest;
use App\Services\Payouts\PayoutSettings;
use App\Services\Payouts\ProviderEarnings;
use App\Support\SaudiIban;
use Illuminate\Http\Request;

/**
 * Payout detail for staff (slice-11-contract.md §12). The IBAN stays masked; the full value is a separate, audited
 * endpoint (D223).
 *
 * @mixin PayoutRequest
 */
class AdminPayoutDetailResource extends AdminPayoutResource
{
    public function toArray(Request $request): array
    {
        $p = $this->resource;
        $canProcess = (bool) $request->user()?->can(Permission::PayoutsProcess->value);
        $open = $p->status === PayoutRequestStatus::Requested;

        return [
            ...parent::toArray($request),
            'holder_name' => $p->holder_name,
            'bank_name' => SaudiIban::bankName($p->bank_name_key),
            'iban_masked' => SaudiIban::mask($p->iban_last4),
            'transfer_reference' => $p->transfer_reference,
            'rejection_reason' => $p->rejection_reason,
            'internal_note' => $p->internal_note,
            'processed_by' => $p->processor ? ['id' => $p->processor->id, 'name' => $p->processor->name] : null,
            'provider_available' => app(PayoutSettings::class)->configured()
                ? $this->cents(app(ProviderEarnings::class)->availableCents($p->provider_id)) : null,
            'history' => $p->activities->map(fn (ActivityEvent $e) => [
                'id' => $e->id,
                'event' => $e->event,
                'from_status' => $e->from_status,
                'to_status' => $e->to_status,
                'actor' => $e->actor ? ['id' => $e->actor->id, 'name' => $e->actor->name] : null,
                'reason' => $e->meta['reason'] ?? null,
                'note' => $e->meta['note'] ?? null, // internal notes are staff-only (`payout_note` events)
                'created_at' => $this->dateTime($e->created_at),
            ])->values()->all(),
            'actions' => [
                'can_mark_paid' => $canProcess && $open,
                'can_reject' => $canProcess && $open,
                'can_view_iban' => $canProcess,
            ],
        ];
    }
}
