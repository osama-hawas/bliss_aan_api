<?php

namespace App\Http\Resources\Api\V1\Provider\Payouts;

use App\Http\Resources\Concerns\DescribesPayouts;
use App\Models\ProviderLedgerEntry;
use App\Services\Payouts\Cents;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One ledger row of her own (slice-11-contract.md §12). `amount` is the signed net effect on her balance.
 *
 * @mixin ProviderLedgerEntry
 */
class LedgerEntryResource extends JsonResource
{
    use DescribesPayouts;

    public function toArray(Request $request): array
    {
        $e = $this->resource;
        $status = $e->status();

        return [
            'id' => $e->id,
            'type' => $e->type->value,
            'type_label' => $e->type->label(),
            'amount' => $this->cents($e->amount_cents),
            'gross' => $this->cents($e->gross_cents),
            'commission' => $this->cents($e->commission_cents),
            'commission_percent' => $e->commission_bps === null ? null : Cents::toDecimal($e->commission_bps),
            'status' => $status->value,
            'status_label' => $status->label(),
            'available_at' => $this->dateTime($e->available_at),
            'booking' => $e->booking ? ['id' => $e->booking->id, 'reference' => $e->booking->reference, 'title' => $e->booking->title] : null,
            'payout_request' => $e->payoutRequest ? ['id' => $e->payoutRequest->id, 'reference' => $e->payoutRequest->reference] : null,
            'created_at' => $this->dateTime($e->created_at),
        ];
    }
}
