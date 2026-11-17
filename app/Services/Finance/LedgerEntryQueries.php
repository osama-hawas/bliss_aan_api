<?php

namespace App\Services\Finance;

use App\Support\RiyadhCalendar;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Cross-provider list of Slice 11's `provider_ledger_entries` (slice-19-contract.md §5), read-only, with the booking,
 * payout and adjustment references joined for display and search.
 */
class LedgerEntryQueries
{
    public const TYPES = ['earning', 'refund_adjustment', 'payout', 'payout_reversal', 'adjustment'];

    public const SORTS = ['created_at', '-created_at', 'amount', '-amount'];

    /**
     * @param  array{q: ?string, provider_id: ?int, type: ?string, from: ?string, to: ?string}  $filters
     */
    public function list(array $filters, ?string $sort, int $perPage): LengthAwarePaginator
    {
        $sort ??= '-created_at';
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $column = ltrim($sort, '-') === 'amount' ? 'e.amount_cents' : 'e.created_at';

        return DB::table('provider_ledger_entries as e')
            ->join('providers as p', 'p.id', '=', 'e.provider_id')
            ->leftJoin('bookings as b', 'b.id', '=', 'e.booking_id')
            ->leftJoin('payout_requests as pr', 'pr.id', '=', 'e.payout_request_id')
            ->leftJoin('ledger_adjustments as la', 'la.ledger_entry_key', '=', 'e.entry_key')
            ->select(
                'e.id', 'e.provider_id', 'e.type', 'e.amount_cents', 'e.gross_cents', 'e.commission_cents', 'e.commission_bps',
                'e.available_at', 'e.created_at', 'e.booking_id', 'e.payout_request_id', 'p.display_name as provider_name',
                'b.reference as booking_reference', 'pr.reference as payout_reference', 'la.id as adjustment_id',
                'la.reference as adjustment_reference', 'la.reason_code as adjustment_reason_code',
            )
            ->when($filters['provider_id'] ?? null, fn (Builder $q, int $id) => $q->where('e.provider_id', $id))
            ->when($filters['type'] ?? null, fn (Builder $q, string $type) => $q->where('e.type', $type))
            ->when($filters['from'] ?? null, fn (Builder $q, string $from) => $q->where('e.created_at', '>=', RiyadhCalendar::dayStart($from)))
            ->when($filters['to'] ?? null, fn (Builder $q, string $to) => $q->where('e.created_at', '<', RiyadhCalendar::dayEnd($to)))
            ->when($filters['q'] ?? null, function (Builder $q, string $term) {
                $term = strtoupper(trim($term));
                $q->where(fn (Builder $w) => $w->where('b.reference', $term)->orWhere('pr.reference', $term)->orWhere('la.reference', $term));
            })
            ->orderBy($column, $direction)
            ->orderBy('e.id', $direction)
            ->paginate($perPage);
    }
}
