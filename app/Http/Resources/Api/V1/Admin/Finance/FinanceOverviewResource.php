<?php

namespace App\Http\Resources\Api\V1\Admin\Finance;

use App\Http\Resources\Concerns\FormatsCents;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * FinanceOverview::build() with cents formatted as money (slice-19-contract.md §5).
 */
class FinanceOverviewResource extends JsonResource
{
    use FormatsCents;

    public function toArray(Request $request): array
    {
        $o = $this->resource;

        return [
            'period' => $o['period'],
            'payments' => [
                'succeeded_count' => $o['payments']['succeeded_count'],
                'captured' => $this->cents($o['payments']['captured_cents']),
                'failed_count' => $o['payments']['failed_count'],
                'by_payable_type' => array_map(fn ($r) => [
                    'payable_type' => $r['payable_type'], 'count' => $r['count'], 'amount' => $this->cents($r['cents']),
                ], $o['payments']['by_payable_type']),
            ],
            'refunds' => [
                'refunded_count' => $o['refunds']['refunded_count'],
                'refunded' => $this->cents($o['refunds']['refunded_cents']),
                'failed_count' => $o['refunds']['failed_count'],
                'in_progress_count' => $o['refunds']['in_progress_count'],
                'in_progress' => $this->cents($o['refunds']['in_progress_cents']),
            ],
            'net_collected' => $this->cents($o['net_collected_cents']),
            'earnings' => [
                'gross' => $this->cents($o['earnings']['gross_cents']),
                'commission' => $this->cents($o['earnings']['commission_cents']),
                'net' => $this->cents($o['earnings']['net_cents']),
            ],
            'adjustments' => [
                'credits' => $this->cents($o['adjustments']['credits_cents']),
                'debits' => $this->cents($o['adjustments']['debits_cents']),
                'pending_count' => $o['adjustments']['pending_count'],
            ],
            'payouts' => [
                'paid_count' => $o['payouts']['paid_count'],
                'paid' => $this->cents($o['payouts']['paid_cents']),
                'open_count' => $o['payouts']['open_count'],
                'open' => $this->cents($o['payouts']['open_cents']),
            ],
            'provider_balances' => [
                'pending' => $this->cents($o['provider_balances']['pending']),
                'available' => $this->cents($o['provider_balances']['available']),
                'in_payout' => $this->cents($o['provider_balances']['in_payout']),
                'negative_count' => $o['provider_balances']['negative_count'],
            ],
            'open_findings' => $o['open_findings'],
        ];
    }
}
