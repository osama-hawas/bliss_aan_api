<?php

namespace App\Services\Payouts;

use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Models\PayoutRequest;
use App\Support\RiyadhCalendar;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * The dashboard payouts queue (slice-11-contract.md §6).
 */
class PayoutQueries
{
    public const SORTS = ['created_at', '-created_at', 'amount', '-amount'];

    /**
     * @param  array{status?: ?string, provider_id?: ?int, from?: ?string, to?: ?string}  $filters
     */
    public function admin(?string $q, array $filters, ?string $sort, int $perPage): LengthAwarePaginator
    {
        $sort ??= 'created_at'; // oldest first: the queue is worked in order
        $column = ltrim($sort, '-') === 'amount' ? 'amount_cents' : 'created_at';
        $q = trim((string) $q);

        return PayoutRequest::query()
            ->with('provider:id,display_name')
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['provider_id'] ?? null, fn ($query, $id) => $query->where('provider_id', $id))
            ->when($filters['from'] ?? null, fn ($query, $from) => $query->where('created_at', '>=', RiyadhCalendar::dayStart($from)))
            ->when($filters['to'] ?? null, fn ($query, $to) => $query->where('created_at', '<', RiyadhCalendar::dayEnd($to)))
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w
                ->where('reference', 'like', '%'.addcslashes($q, '%_\\').'%')
                ->orWhereHas('provider', fn ($p) => $p->where('display_name', 'like', '%'.addcslashes($q, '%_\\').'%'))))
            ->orderBy($column, str_starts_with($sort, '-') ? 'desc' : 'asc')
            ->orderBy('id', str_starts_with($sort, '-') ? 'desc' : 'asc')
            ->paginate($perPage);
    }

    public function adminOne(int $id): PayoutRequest
    {
        return PayoutRequest::query()
            ->with(['provider:id,display_name,user_id', 'processor:id,name', 'activities' => fn ($q) => $q->with('actor:id,name')->latest('id')])
            ->whereKey($id)->first()
            ?? throw new ApiException(ErrorCode::NotFound);
    }
}
