<?php

namespace App\Http\Resources\Api\V1\Provider\Payouts;

use App\Http\Resources\Concerns\DescribesPayouts;
use App\Services\Payouts\Cents;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `GET provider/earnings` (slice-11-contract.md §12). The resource is ProviderEarnings::summary().
 */
class EarningsSummaryResource extends JsonResource
{
    use DescribesPayouts;

    public function toArray(Request $request): array
    {
        $s = $this->resource;
        $b = $s['balances'];

        return [
            'currency' => config('app.currency'),
            'commission_percent' => Cents::toDecimal($s['commission_bps']),
            'hold_days' => $s['hold_days'],
            'min_amount' => $this->cents($s['min_cents']),
            'available' => $this->cents($b['available']),
            'pending' => $this->cents($b['pending']),
            'in_payout' => $this->cents($b['in_payout']),
            'paid_out' => $this->cents($b['paid_out']),
            'lifetime' => [
                'gross' => $this->cents($b['gross']),
                'commission' => $this->cents($b['commission']),
                'net' => $this->cents($b['net']),
            ],
            'next_available_at' => $this->dateTime($b['next_available_at']),
            'can_request' => $s['block'] === null,
            'request_block' => $s['block']?->value,
            'payout_account' => $this->payoutAccount($s['account']),
            'open_payout_request' => $this->providerPayoutRequest($s['open_request']),
        ];
    }
}
