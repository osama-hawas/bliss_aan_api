<?php

namespace App\Services\Finance;

use App\Enums\ReconciliationCheck;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Read-only reconciliation (slice-19-contract.md §5, D296): each check is one query over the S10 / S11 / S19 tables
 * that returns rows in one shape — `id, kind, reference, status, amount_cents, provider_id, provider_name, client_id,
 * client_name, link_type, link_id, created_at, detail` — so the dashboard shows every finding in one table. Nothing is
 * changed here; findings are fixed by S10's reconcile command, S11's back-fill, a refund or an adjustment.
 */
class Reconciliation
{
    public const DAILY_MAX_DAYS = 92;

    public function __construct(private readonly ProviderBalances $balances) {}

    /**
     * @return list<array{check: ReconciliationCheck, count: int, amount_cents: ?int}>
     */
    public function summary(): array
    {
        return array_map(function (ReconciliationCheck $check) {
            $query = $this->query($check);
            $count = (clone $query)->count();
            $amount = $count === 0 ? 0 : (int) DB::query()->fromSub($query, 'x')->sum('amount_cents');

            return ['check' => $check, 'count' => $count, 'amount_cents' => $this->hasAmount($check) ? $amount : null];
        }, ReconciliationCheck::cases());
    }

    public function totalFindings(): int
    {
        return array_sum(array_map(fn (ReconciliationCheck $c) => $this->query($c)->count(), ReconciliationCheck::cases()));
    }

    public function rows(ReconciliationCheck $check, int $perPage): LengthAwarePaginator
    {
        return DB::query()->fromSub($this->query($check), 'x')->orderBy('created_at')->orderBy('id')->paginate($perPage);
    }

    /**
     * Per Riyadh day: captured, refunded, net, earnings net, commission, adjustments net, payouts paid (cents).
     *
     * @return list<array<string, int|string>>
     */
    public function daily(FinancePeriod $period): array
    {
        $tz = "'+03:00'"; // Asia/Riyadh has no daylight saving (RiyadhCalendar)
        $day = fn (string $column) => "DATE(CONVERT_TZ({$column}, '+00:00', {$tz}))";
        $between = fn (Builder $q, string $column) => $q->where($column, '>=', $period->start)->where($column, '<', $period->end);

        $payments = $between(DB::table('payments')->where('status', 'succeeded'), 'paid_at')
            ->selectRaw($day('paid_at').' AS d, COUNT(*) AS c, CAST(SUM(amount * 100) AS SIGNED) AS cents')->groupBy('d')->get()->keyBy('d');
        $refunds = $between(DB::table('refunds')->where('status', 'refunded'), 'processed_at')
            ->selectRaw($day('processed_at').' AS d, CAST(SUM(amount * 100) AS SIGNED) AS cents')->groupBy('d')->get()->keyBy('d');
        $ledger = $between(DB::table('provider_ledger_entries'), 'created_at')
            ->selectRaw($day('created_at').' AS d')
            ->selectRaw("CAST(COALESCE(SUM(CASE WHEN type IN ('earning','refund_adjustment') THEN amount_cents END), 0) AS SIGNED) AS net")
            ->selectRaw("CAST(COALESCE(SUM(CASE WHEN type IN ('earning','refund_adjustment') THEN commission_cents END), 0) AS SIGNED) AS commission")
            ->selectRaw("CAST(COALESCE(SUM(CASE WHEN type = 'adjustment' THEN amount_cents END), 0) AS SIGNED) AS adjustments")
            ->groupBy('d')->get()->keyBy('d');
        $payouts = $between(DB::table('payout_requests')->where('status', 'paid'), 'processed_at')
            ->selectRaw($day('processed_at').' AS d, CAST(SUM(amount_cents) AS SIGNED) AS cents')->groupBy('d')->get()->keyBy('d');

        return array_map(function (string $date) use ($payments, $refunds, $ledger, $payouts) {
            $captured = (int) ($payments[$date]->cents ?? 0);
            $refunded = (int) ($refunds[$date]->cents ?? 0);

            return [
                'date' => $date,
                'payments_count' => (int) ($payments[$date]->c ?? 0),
                'captured_cents' => $captured,
                'refunded_cents' => $refunded,
                'net_cents' => $captured - $refunded,
                'earnings_net_cents' => (int) ($ledger[$date]->net ?? 0),
                'commission_cents' => (int) ($ledger[$date]->commission ?? 0),
                'adjustments_net_cents' => (int) ($ledger[$date]->adjustments ?? 0),
                'payouts_paid_cents' => (int) ($payouts[$date]->cents ?? 0),
            ];
        }, $period->dates());
    }

    private function hasAmount(ReconciliationCheck $check): bool
    {
        return $check !== ReconciliationCheck::WebhooksUnprocessed;
    }

    private function query(ReconciliationCheck $check): Builder
    {
        $now = now();

        return match ($check) {
            ReconciliationCheck::StalePayments => $this->payments()
                ->where(fn (Builder $q) => $q->where('py.status', 'unknown')
                    ->orWhere(fn (Builder $w) => $w->whereIn('py.status', ['pending', 'requires_action'])->where('py.expires_at', '<', $now))),
            ReconciliationCheck::FulfilmentPending => $this->payments()
                ->where('py.status', 'succeeded')->where('py.fulfilment_status', 'pending'),
            ReconciliationCheck::FulfilmentFailedUnrefunded => $this->payments('py.fulfilment_error')
                ->where('py.status', 'succeeded')->where('py.fulfilment_status', 'failed')->whereColumn('py.refunded_amount', '<', 'py.amount'),
            ReconciliationCheck::PaymentRefundMismatch => $this->payments(
                "CONCAT(CAST(py.refunded_amount AS CHAR), ' / ', CAST(COALESCE((SELECT SUM(rf.amount) FROM refunds rf WHERE rf.payment_id = py.id AND rf.status IN ('in_progress','refunded')), 0) AS CHAR))"
            )->whereRaw("CAST(py.refunded_amount * 100 AS SIGNED) <> CAST(COALESCE((SELECT SUM(rf.amount * 100) FROM refunds rf WHERE rf.payment_id = py.id AND rf.status IN ('in_progress','refunded')), 0) AS SIGNED)"),
            ReconciliationCheck::RefundsInProgress => $this->refunds()->where('r.status', 'in_progress'),
            ReconciliationCheck::RefundsFailed => $this->refunds()->where('r.status', 'failed')->whereColumn('py.refunded_amount', '<', 'py.amount'),
            ReconciliationCheck::WebhooksUnprocessed => DB::table('payment_webhook_events as w')
                ->where(fn (Builder $q) => $q->where('w.result', 'unmatched')->orWhereNull('w.processed_at'))
                ->selectRaw("w.id, 'webhook_event' AS kind, w.event_id AS reference, COALESCE(w.result, 'unprocessed') AS status, NULL AS amount_cents,
                    NULL AS provider_id, NULL AS provider_name, NULL AS client_id, NULL AS client_name, NULL AS link_type, NULL AS link_id,
                    w.received_at AS created_at, CONCAT(w.gateway, ' · ', w.type) AS detail"),
            ReconciliationCheck::MissingEarnings => DB::table('bookings as b')
                ->join('payments as py', 'py.id', '=', 'b.payment_id')
                ->join('providers as p', 'p.id', '=', 'b.provider_id')
                ->leftJoin('users as u', 'u.id', '=', 'b.client_id')
                ->where('b.status', 'completed')->whereIn('b.payment_status', ['paid', 'partially_refunded'])->where('py.status', 'succeeded')
                ->whereNotExists(fn (Builder $q) => $q->from('provider_ledger_entries as e')->whereRaw("e.entry_key = CONCAT('earning:', b.id)"))
                ->selectRaw("b.id, 'booking' AS kind, b.reference, b.payment_status AS status, CAST(py.amount * 100 AS SIGNED) AS amount_cents,
                    p.id AS provider_id, p.display_name AS provider_name, u.id AS client_id, u.name AS client_name, 'booking' AS link_type, b.id AS link_id,
                    b.completed_at AS created_at, py.reference AS detail"),
            ReconciliationCheck::RefundNotInLedger => $this->refunds()
                ->join('bookings as b', 'b.payment_id', '=', 'r.payment_id')
                ->join('provider_ledger_entries as e', fn ($j) => $j->whereRaw("e.entry_key = CONCAT('earning:', b.id)"))
                ->where('r.status', 'refunded')
                ->whereNotExists(fn (Builder $q) => $q->from('provider_ledger_entries as ra')->whereRaw("ra.entry_key = CONCAT('refund:', r.id)"))
                ->whereRaw("NOT JSON_CONTAINS(COALESCE(JSON_EXTRACT(e.meta, '$.netted_refund_ids'), JSON_ARRAY()), CAST(r.id AS JSON))"),
            ReconciliationCheck::NegativeBalances => DB::query()->fromSub($this->balances->negative(), 'n')
                ->selectRaw("n.id, 'provider' AS kind, NULL AS reference, n.status, n.available_cents AS amount_cents, n.id AS provider_id,
                    n.display_name AS provider_name, NULL AS client_id, NULL AS client_name, 'provider_balance' AS link_type, n.id AS link_id,
                    NULL AS created_at, NULL AS detail"),
            ReconciliationCheck::PendingAdjustments => DB::table('ledger_adjustments as la')
                ->join('providers as p', 'p.id', '=', 'la.provider_id')
                ->where('la.status', 'pending_approval')
                ->selectRaw("la.id, 'adjustment' AS kind, la.reference, la.status,
                    CASE WHEN la.direction = 'debit' THEN -CAST(la.amount_cents AS SIGNED) ELSE CAST(la.amount_cents AS SIGNED) END AS amount_cents,
                    p.id AS provider_id, p.display_name AS provider_name, NULL AS client_id, NULL AS client_name, 'adjustment' AS link_type, la.id AS link_id,
                    la.created_at, la.reason_code AS detail"),
        };
    }

    private function payments(string $detail = 'NULL'): Builder
    {
        return DB::table('payments as py')->leftJoin('users as u', 'u.id', '=', 'py.user_id')
            ->selectRaw("py.id, 'payment' AS kind, py.reference, py.status, CAST(py.amount * 100 AS SIGNED) AS amount_cents,
                NULL AS provider_id, NULL AS provider_name, u.id AS client_id, u.name AS client_name, 'payment' AS link_type, py.id AS link_id,
                py.created_at, {$detail} AS detail");
    }

    private function refunds(): Builder
    {
        return DB::table('refunds as r')->join('payments as py', 'py.id', '=', 'r.payment_id')->leftJoin('users as u', 'u.id', '=', 'py.user_id')
            ->selectRaw("r.id, 'refund' AS kind, r.reference, r.status, CAST(r.amount * 100 AS SIGNED) AS amount_cents,
                NULL AS provider_id, NULL AS provider_name, u.id AS client_id, u.name AS client_name, 'payment' AS link_type, py.id AS link_id,
                r.created_at, r.failure_code AS detail");
    }
}
