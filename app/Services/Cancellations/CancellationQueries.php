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

    public function admin(?string $status, ?string $role, ?string $sort, int $perPage, ?string $q = null): LengthAwarePaginator
    {
        $sort ??= 'created_at';

        return CancellationRequest::query()->with(self::WITH)
            // S17 (§5.3): CN/BK reference, client or provider name
            ->when($q, fn ($query, string $text) => $query->where(fn ($w) => $w
                ->where('reference', 'like', "%{$text}%")
                ->orWhereHas('booking', fn ($b) => $b->where('reference', 'like', "%{$text}%")
                    ->orWhereHas('client', fn ($c) => $c->where('name', 'like', "%{$text}%"))
                    ->orWhereHas('provider', fn ($p) => $p->where('display_name', 'like', "%{$text}%")))))
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
