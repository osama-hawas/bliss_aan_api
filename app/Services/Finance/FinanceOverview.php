<?php

namespace App\Services\Finance;

use Illuminate\Support\Facades\DB;

/**
 * Finance overview for a Riyadh-day period (slice-19-contract.md §5, D294). Reads Slice 10's `payments` / `refunds`
 * and Slice 11's ledger and payout tables through the query builder. Decimal columns are summed as exact cents in SQL
 * (`amount * 100` on DECIMAL is exact), never as floats.
 */
class FinanceOverview
{
    public const MAX_DAYS = 366;

    public function __construct(
        private readonly ProviderBalances $balances,
        private readonly AdjustmentQueries $adjustments,
        private readonly Reconciliation $reconciliation,
    ) {}

    /**
     * @return array<string, mixed> cents everywhere (the resource formats money)
     */
    public function build(FinancePeriod $period): array
    {
        [$start, $end] = [$period->start, $period->end];

        $byType = DB::table('payments')->where('status', 'succeeded')->where('paid_at', '>=', $start)->where('paid_at', '<', $end)
            ->groupBy('payable_type')->select('payable_type')
            ->selectRaw('COUNT(*) AS c, CAST(COALESCE(SUM(amount * 100), 0) AS SIGNED) AS cents')->get();
        $captured = (int) $byType->sum('cents');
        $failed = DB::table('payments')->whereIn('status', ['failed', 'cancelled'])->where('created_at', '>=', $start)->where('created_at', '<', $end)->count();

        $refunded = DB::table('refunds')->where('status', 'refunded')->where('processed_at', '>=', $start)->where('processed_at', '<', $end)
            ->selectRaw('COUNT(*) AS c, CAST(COALESCE(SUM(amount * 100), 0) AS SIGNED) AS cents')->first();
        $refundsOpen = DB::table('refunds')->where('status', 'in_progress')
            ->selectRaw('COUNT(*) AS c, CAST(COALESCE(SUM(amount * 100), 0) AS SIGNED) AS cents')->first();
        $refundsFailed = DB::table('refunds')->where('status', 'failed')->where('created_at', '>=', $start)->where('created_at', '<', $end)->count();

        $ledger = DB::table('provider_ledger_entries')->where('created_at', '>=', $start)->where('created_at', '<', $end)
            ->selectRaw("CAST(COALESCE(SUM(CASE WHEN type IN ('earning','refund_adjustment') THEN gross_cents END), 0) AS SIGNED) AS gross")
            ->selectRaw("CAST(COALESCE(SUM(CASE WHEN type IN ('earning','refund_adjustment') THEN commission_cents END), 0) AS SIGNED) AS commission")
            ->selectRaw("CAST(COALESCE(SUM(CASE WHEN type IN ('earning','refund_adjustment') THEN amount_cents END), 0) AS SIGNED) AS net")
            ->selectRaw("CAST(COALESCE(SUM(CASE WHEN type = 'adjustment' AND amount_cents > 0 THEN amount_cents END), 0) AS SIGNED) AS credits")
            ->selectRaw("CAST(COALESCE(SUM(CASE WHEN type = 'adjustment' AND amount_cents < 0 THEN -amount_cents END), 0) AS SIGNED) AS debits")
            ->first();

        $paid = DB::table('payout_requests')->where('status', 'paid')->where('processed_at', '>=', $start)->where('processed_at', '<', $end)
            ->selectRaw('COUNT(*) AS c, CAST(COALESCE(SUM(amount_cents), 0) AS SIGNED) AS cents')->first();
        $open = DB::table('payout_requests')->where('status', 'requested')
            ->selectRaw('COUNT(*) AS c, CAST(COALESCE(SUM(amount_cents), 0) AS SIGNED) AS cents')->first();

        return [
            'period' => ['from' => $period->from, 'to' => $period->to],
            'payments' => [
                'succeeded_count' => (int) $byType->sum('c'),
                'captured_cents' => $captured,
                'failed_count' => $failed,
                'by_payable_type' => $byType->map(fn ($r) => ['payable_type' => $r->payable_type, 'count' => (int) $r->c, 'cents' => (int) $r->cents])->values()->all(),
            ],
            'refunds' => [
                'refunded_count' => (int) $refunded->c,
                'refunded_cents' => (int) $refunded->cents,
                'failed_count' => $refundsFailed,
                'in_progress_count' => (int) $refundsOpen->c,
                'in_progress_cents' => (int) $refundsOpen->cents,
            ],
            'net_collected_cents' => $captured - (int) $refunded->cents,
            'earnings' => ['gross_cents' => (int) $ledger->gross, 'commission_cents' => (int) $ledger->commission, 'net_cents' => (int) $ledger->net],
            'adjustments' => ['credits_cents' => (int) $ledger->credits, 'debits_cents' => (int) $ledger->debits, 'pending_count' => $this->adjustments->pendingCount()],
            'payouts' => ['paid_count' => (int) $paid->c, 'paid_cents' => (int) $paid->cents, 'open_count' => (int) $open->c, 'open_cents' => (int) $open->cents],
            'provider_balances' => $this->balances->totals(),
            'open_findings' => $this->reconciliation->totalFindings(),
        ];
    }
}
