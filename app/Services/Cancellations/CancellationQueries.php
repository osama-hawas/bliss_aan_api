<?php

namespace App\Services\Cancellations;

use App\Models\CancellationRequest;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Dashboard lookups for cancellation requests (D131).
 */
class CancellationQueries
{
    private const WITH = ['booking.provider', 'booking.client', 'booking.serviceRequest', 'requester', 'decider'];

    public function admin(?string $status, ?string $role, ?string $sort, int $perPage): LengthAwarePaginator
    {
        $sort ??= 'created_at';

        return CancellationRequest::query()->with(self::WITH)
            ->when($status, fn ($q, $s) => $q->where('status', $s))
            ->when($role, fn ($q, $r) => $q->where('requester_role', $r))
            ->orderBy('created_at', str_starts_with($sort, '-') ? 'desc' : 'asc')
            ->orderBy('id', str_starts_with($sort, '-') ? 'desc' : 'asc')
            ->paginate($perPage);
    }

    public function adminOne(int $id): CancellationRequest
    {
        return CancellationRequest::query()->with(self::WITH)->findOrFail($id);
    }
}
