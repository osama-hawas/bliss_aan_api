<?php

namespace App\Http\Resources\Api\V1\Admin\Finance;

use App\Enums\LedgerAdjustmentReason;
use App\Http\Resources\Concerns\FormatsApiValues;
use App\Http\Resources\Concerns\FormatsCents;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * One S11 ledger entry across providers. The type label comes from `lang/{ar,en}/finance.php` (S19 does not import S11's
 * enum, D299).
 */
class AdminLedgerEntryResource extends JsonResource
{
    use FormatsApiValues, FormatsCents;

    public function toArray(Request $request): array
    {
        $e = $this->resource;
        $reason = $e->adjustment_reason_code ? LedgerAdjustmentReason::tryFrom($e->adjustment_reason_code) : null;

        return [
            'id' => (int) $e->id,
            'provider' => ['id' => (int) $e->provider_id, 'display_name' => $e->provider_name],
            'type' => $e->type,
            'type_label' => __('finance.entry_types.'.$e->type),
            'amount' => $this->cents($e->amount_cents),
            'gross' => $this->cents($e->gross_cents),
            'commission' => $this->cents($e->commission_cents),
            'commission_percent' => $e->commission_bps === null ? null : number_format(((int) $e->commission_bps) / 100, 2, '.', ''),
            'booking' => $e->booking_id ? ['id' => (int) $e->booking_id, 'reference' => $e->booking_reference] : null,
            'payout' => $e->payout_request_id ? ['id' => (int) $e->payout_request_id, 'reference' => $e->payout_reference] : null,
            'adjustment' => $e->adjustment_id ? ['id' => (int) $e->adjustment_id, 'reference' => $e->adjustment_reference, 'reason_code' => $reason?->value, 'reason_code_label' => $reason?->label()] : null,
            'available_at' => $this->dateTime(Carbon::parse($e->available_at, 'UTC')),
            'created_at' => $this->dateTime(Carbon::parse($e->created_at, 'UTC')),
        ];
    }
}
