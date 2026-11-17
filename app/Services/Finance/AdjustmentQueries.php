<?php

namespace App\Services\Finance;

use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Models\ActivityEvent;
use App\Models\LedgerAdjustment;
use App\Support\RiyadhCalendar;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Dashboard lookups for ledger adjustments (slice-19-contract.md §6).
 */
class AdjustmentQueries
{
    public const SORTS = ['created_at', '-created_at', 'amount', '-amount'];

    private const WITH = ['provider:id,display_name', 'booking:id,reference', 'requester:id,name', 'decider:id,name'];

    /**
     * @param  array{q: ?string, status: ?string, direction: ?string, reason_code: ?string, provider_id: ?int, from: ?string, to: ?string}  $filters
     */
    public function list(array $filters, ?string $sort, int $perPage): LengthAwarePaginator
    {
        $sort ??= '-created_at';
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';

        $page = LedgerAdjustment::query()->with(self::WITH)
            ->when($filters['status'] ?? null, fn (Builder $q, $v) => $q->where('status', $v))
            ->when($filters['direction'] ?? null, fn (Builder $q, $v) => $q->where('direction', $v))
            ->when($filters['reason_code'] ?? null, fn (Builder $q, $v) => $q->where('reason_code', $v))
            ->when($filters['provider_id'] ?? null, fn (Builder $q, $v) => $q->where('provider_id', $v))
            ->when($filters['from'] ?? null, fn (Builder $q, $v) => $q->where('created_at', '>=', RiyadhCalendar::dayStart($v)))
            ->when($filters['to'] ?? null, fn (Builder $q, $v) => $q->where('created_at', '<', RiyadhCalendar::dayEnd($v)))
            ->when($filters['q'] ?? null, function (Builder $q, string $term) {
                $term = trim($term);
                $q->where(fn (Builder $w) => $w->where('reference', strtoupper($term))
                    ->orWhereHas('provider', fn (Builder $p) => $p->where('display_name', 'like', '%'.addcslashes($term, '%_\\').'%')));
            })
            ->orderBy(ltrim($sort, '-') === 'amount' ? 'amount_cents' : 'created_at', $direction)
            ->orderBy('id', $direction)
            ->paginate($perPage);

        $this->fillPayoutReferences($page->getCollection());

        return $page;
    }

    public function one(int $id): LedgerAdjustment
    {
        $adjustment = LedgerAdjustment::query()->with(self::WITH)->find($id)
            ?? throw new ApiException(ErrorCode::NotFound);
        $this->fillPayoutReferences(collect([$adjustment]));

        return $adjustment;
    }

    /**
     * @return Collection<int, ActivityEvent>
     */
    public function history(LedgerAdjustment $adjustment): Collection
    {
        return ActivityEvent::query()->with('actor:id,name')
            ->where('subject_type', $adjustment->getMorphClass())->where('subject_id', $adjustment->id)
            ->orderBy('id')->get();
    }

    public function pendingCount(): int
    {
        return LedgerAdjustment::query()->where('status', 'pending_approval')->count();
    }

    /**
     * @param  Collection<int, LedgerAdjustment>  $adjustments
     */
    private function fillPayoutReferences(Collection $adjustments): void
    {
        $ids = $adjustments->pluck('payout_request_id')->filter()->unique()->values();
        if ($ids->isEmpty()) {
            return;
        }
        $references = DB::table('payout_requests')->whereIn('id', $ids)->pluck('reference', 'id');
        $adjustments->each(fn (LedgerAdjustment $a) => $a->payoutReference = $a->payout_request_id ? ($references[$a->payout_request_id] ?? null) : null);
    }
}
