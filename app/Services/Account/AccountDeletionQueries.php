<?php

namespace App\Services\Account;

use App\Enums\DeletionRequestStatus;
use App\Models\AccountDeletionRequest;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * The staff queue of account deletion requests (S22, D336): clients and providers, pending first by default.
 */
class AccountDeletionQueries
{
    /**
     * @param  array{q: ?string, status: ?string, user_type: ?string, blocked: ?bool}  $filters
     */
    public function list(array $filters, int $perPage): LengthAwarePaginator
    {
        return AccountDeletionRequest::query()
            ->with(['user', 'processor'])
            ->when($filters['status'], fn (Builder $q, string $s) => $q->where('status', $s))
            ->when($filters['user_type'], fn (Builder $q, string $t) => $q->where('user_type', $t))
            ->when($filters['blocked'] !== null, fn (Builder $q) => $filters['blocked']
                ? $q->where('status', DeletionRequestStatus::Pending)->whereNotNull('blocked_by')
                : $q->whereNull('blocked_by'))
            ->when($filters['q'], fn (Builder $q, string $text) => $q->whereHas('user', fn (Builder $u) => $u
                ->where('name', 'like', "%{$text}%")
                ->when(ltrim($text, '+0') !== '', fn (Builder $p) => $p->orWhere('phone', 'like', '%'.ltrim($text, '+0').'%'))))
            // Pending first (oldest = most overdue first), then closed ones newest first.
            ->orderByRaw('case when status = ? then 0 else 1 end', [DeletionRequestStatus::Pending->value])
            ->orderByRaw('case when status = ? then id else -id end', [DeletionRequestStatus::Pending->value])
            ->paginate($perPage);
    }

    public function find(int $id): AccountDeletionRequest
    {
        return AccountDeletionRequest::query()->with(['user', 'processor'])->findOrFail($id);
    }
}
