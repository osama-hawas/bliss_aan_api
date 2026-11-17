<?php

namespace App\Services\Store\Returns;

use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Models\StoreReturn;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Scoped lookups and lists of store returns (S23). A foreign id is a 404.
 */
class StoreReturnQueries
{
    /** Her returns, newest first; optionally of one of her orders. */
    public function forClient(User $client, ?int $orderId, ?string $status, int $perPage): LengthAwarePaginator
    {
        return StoreReturn::query()->where('user_id', $client->id)
            ->when($orderId, fn (Builder $q, int $id) => $q->where('store_order_id', $id))
            ->when($status === 'open', fn (Builder $q) => $q->whereIn('status', ['requested', 'approved', 'received']))
            ->when($status === 'done', fn (Builder $q) => $q->whereIn('status', ['refunded', 'rejected', 'cancelled']))
            ->with($this->clientRelations())
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate($perPage)->withQueryString();
    }

    public function clientReturn(User $client, int $id, ?int $orderId = null): StoreReturn
    {
        return StoreReturn::query()->where('user_id', $client->id)
            ->when($orderId, fn (Builder $q, int $order) => $q->where('store_order_id', $order))
            ->with($this->clientRelations())->find($id)
            ?? throw new ApiException(ErrorCode::NotFound);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function forAdmin(?string $q, array $filters, ?string $sort, int $perPage): LengthAwarePaginator
    {
        $direction = str_starts_with((string) ($sort ?? '-created_at'), '-') ? 'desc' : 'asc';
        $column = ltrim((string) ($sort ?? '-created_at'), '-') === 'refund_amount' ? 'refund_amount' : 'created_at';

        return StoreReturn::query()
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $status === 'open'
                ? $query->whereIn('status', ['requested', 'approved', 'received'])
                : $query->where('status', $status))
            ->when($filters['reason'] ?? null, fn (Builder $query, string $reason) => $query->where('reason_code', $reason))
            ->when($filters['store_order_id'] ?? null, fn (Builder $query, $id) => $query->where('store_order_id', (int) $id))
            ->when($filters['from'] ?? null, fn (Builder $query, string $from) => $query->where('created_at', '>=', $from.' 00:00:00'))
            ->when($filters['to'] ?? null, fn (Builder $query, string $to) => $query->where('created_at', '<=', $to.' 23:59:59'))
            ->when($q, function (Builder $query, string $text) {
                $like = '%'.addcslashes($text, '\\%_').'%';
                $digits = preg_replace('/\D+/', '', $text);
                $query->where(fn (Builder $w) => $w->where('reference', 'like', $like)
                    ->orWhereHas('order', fn (Builder $o) => $o->where('reference', 'like', $like))
                    ->when(strlen((string) $digits) >= 4, fn (Builder $p) => $p->orWhereHas('user', fn (Builder $u) => $u->where('phone', 'like', '%'.$digits.'%'))));
            })
            ->with(['user', 'order:id,reference'])
            ->withSum('items as items_count', 'quantity')
            ->orderBy($column, $direction)->orderBy('id', $direction)
            ->paginate($perPage)->withQueryString();
    }

    public function adminReturn(int $id): StoreReturn
    {
        return StoreReturn::query()->with([
            'user', 'order.payment', 'items.variant.product.image', 'photos', 'refund', 'policyVersion',
            'activity' => fn ($a) => $a->with('actor')->orderBy('id'),
        ])->withSum('items as items_count', 'quantity')->find($id) ?? throw new ApiException(ErrorCode::NotFound);
    }

    /** @return list<string|\Closure> */
    private function clientRelations(): array
    {
        return [
            'order:id,reference', 'items.variant.product.image', 'photos', 'refund',
            'activity' => fn ($a) => $a->where('visibility', 'all')->whereNotNull('to_status')->orderBy('id'),
        ];
    }
}
