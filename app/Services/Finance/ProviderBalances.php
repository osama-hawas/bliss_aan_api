<?php

namespace App\Services\Finance;

use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Provider balances across providers (slice-19-contract.md §5, D295), computed from Slice 11's append-only
 * `provider_ledger_entries` and `payout_requests` the same way S11 §4.3 computes her own summary:
 * pending = earnings + refund adjustments not yet available; available = every entry already available (payout debits
 * included); in_payout / paid_out = requested / paid payout requests. Cents throughout.
 */
class ProviderBalances
{
    public const SORTS = ['available', '-available', 'pending', '-pending', 'in_payout', '-in_payout', 'name', '-name'];

    private const INCOME = "'earning','refund_adjustment'";

    /**
     * @param  array{q: ?string, balance: ?string}  $filters
     */
    public function list(array $filters, ?string $sort, int $perPage): LengthAwarePaginator
    {
        $sort ??= '-available';
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $column = match (ltrim($sort, '-')) {
            'pending' => 'pending_cents',
            'in_payout' => 'in_payout_cents',
            'name' => 'p.display_name',
            default => 'available_cents',
        };

        return $this->base()
            ->when($filters['q'] ?? null, fn (Builder $q, string $term) => $q->where('p.display_name', 'like', '%'.addcslashes($term, '%_\\').'%'))
            ->when($filters['balance'] ?? null, function (Builder $q, string $balance) {
                $expr = DB::raw('COALESCE(l.available_cents, 0)');
                match ($balance) {
                    'negative' => $q->where($expr, '<', 0),
                    'positive' => $q->where($expr, '>', 0),
                    default => $q->where($expr, '=', 0),
                };
            })
            ->orderBy($column, $direction)
            ->orderBy('p.id')
            ->paginate($perPage);
    }

    public function one(int $providerId): object
    {
        $row = $this->base(withEmpty: true)->where('p.id', $providerId)->first()
            ?? throw new ApiException(ErrorCode::NotFound);

        $row->last_entry_at = DB::table('provider_ledger_entries')->where('provider_id', $providerId)->max('created_at');

        return $row;
    }

    /**
     * Totals over every provider (overview, "now").
     *
     * @return array{pending: int, available: int, in_payout: int, negative_count: int}
     */
    public function totals(): array
    {
        $l = $this->ledgerAggregate();
        $row = DB::query()->fromSub($l, 'l')->selectRaw(
            'COALESCE(SUM(pending_cents),0) AS pending, COALESCE(SUM(available_cents),0) AS available, COALESCE(SUM(CASE WHEN available_cents < 0 THEN 1 ELSE 0 END),0) AS negative_count'
        )->first();
        $inPayout = (int) DB::table('payout_requests')->where('status', 'requested')->sum('amount_cents');

        return [
            'pending' => (int) $row->pending,
            'available' => (int) $row->available,
            'in_payout' => $inPayout,
            'negative_count' => (int) $row->negative_count,
        ];
    }

    /**
     * Providers with a negative available balance (reconciliation check).
     */
    public function negative(): Builder
    {
        return $this->base()->where(DB::raw('COALESCE(l.available_cents, 0)'), '<', 0)->orderBy('available_cents')->orderBy('p.id');
    }

    private function base(bool $withEmpty = false): Builder
    {
        $query = DB::table('providers as p')
            ->leftJoinSub($this->ledgerAggregate(), 'l', 'l.provider_id', '=', 'p.id')
            ->leftJoinSub($this->payoutAggregate(), 'r', 'r.provider_id', '=', 'p.id')
            ->select('p.id', 'p.display_name', 'p.status')
            ->selectRaw('COALESCE(l.pending_cents, 0) AS pending_cents')
            ->selectRaw('COALESCE(l.available_cents, 0) AS available_cents')
            ->selectRaw('COALESCE(l.lifetime_gross_cents, 0) AS lifetime_gross_cents')
            ->selectRaw('COALESCE(l.lifetime_commission_cents, 0) AS lifetime_commission_cents')
            ->selectRaw('COALESCE(l.lifetime_net_cents, 0) AS lifetime_net_cents')
            ->selectRaw('COALESCE(l.adjustments_net_cents, 0) AS adjustments_net_cents')
            ->selectRaw('COALESCE(r.in_payout_cents, 0) AS in_payout_cents')
            ->selectRaw('COALESCE(r.paid_out_cents, 0) AS paid_out_cents');

        return $withEmpty ? $query : $query->where(fn (Builder $q) => $q->whereNotNull('l.provider_id')->orWhereNotNull('r.provider_id'));
    }

    private function ledgerAggregate(): Builder
    {
        $now = now()->toDateTimeString();

        return DB::table('provider_ledger_entries')
            ->select('provider_id')
            ->selectRaw('SUM(CASE WHEN type IN ('.self::INCOME.') AND available_at > ? THEN amount_cents ELSE 0 END) AS pending_cents', [$now])
            ->selectRaw('SUM(CASE WHEN available_at <= ? THEN amount_cents ELSE 0 END) AS available_cents', [$now])
            ->selectRaw('SUM(CASE WHEN type IN ('.self::INCOME.') THEN COALESCE(gross_cents, 0) ELSE 0 END) AS lifetime_gross_cents')
            ->selectRaw('SUM(CASE WHEN type IN ('.self::INCOME.') THEN COALESCE(commission_cents, 0) ELSE 0 END) AS lifetime_commission_cents')
            ->selectRaw('SUM(CASE WHEN type IN ('.self::INCOME.') THEN amount_cents ELSE 0 END) AS lifetime_net_cents')
            ->selectRaw("SUM(CASE WHEN type = 'adjustment' THEN amount_cents ELSE 0 END) AS adjustments_net_cents")
            ->groupBy('provider_id');
    }

    private function payoutAggregate(): Builder
    {
        return DB::table('payout_requests')
            ->select('provider_id')
            ->selectRaw("SUM(CASE WHEN status = 'requested' THEN amount_cents ELSE 0 END) AS in_payout_cents")
            ->selectRaw("SUM(CASE WHEN status = 'paid' THEN amount_cents ELSE 0 END) AS paid_out_cents")
            ->groupBy('provider_id');
    }
}
