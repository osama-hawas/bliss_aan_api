<?php

namespace App\Http\Resources\Api\V1\Admin\Finance;

use App\Http\Resources\Concerns\FormatsApiValues;
use App\Http\Resources\Concerns\FormatsCents;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * One provider's balances (ProviderBalances row, cents → money).
 */
class AdminProviderBalanceResource extends JsonResource
{
    use FormatsApiValues, FormatsCents;

    public function toArray(Request $request): array
    {
        $r = $this->resource;

        return [
            'provider' => ['id' => (int) $r->id, 'display_name' => $r->display_name, 'status' => $r->status],
            'available' => $this->cents($r->available_cents),
            'pending' => $this->cents($r->pending_cents),
            'in_payout' => $this->cents($r->in_payout_cents),
            'paid_out' => $this->cents($r->paid_out_cents),
            'lifetime' => [
                'gross' => $this->cents($r->lifetime_gross_cents),
                'commission' => $this->cents($r->lifetime_commission_cents),
                'net' => $this->cents($r->lifetime_net_cents),
            ],
            'adjustments_net' => $this->cents($r->adjustments_net_cents),
            'is_negative' => (int) $r->available_cents < 0,
            'last_entry_at' => $this->when(property_exists($r, 'last_entry_at'), fn () => $r->last_entry_at ? $this->dateTime(Carbon::parse($r->last_entry_at, 'UTC')) : null),
        ];
    }
}
