<?php

namespace App\Services\Store;

use App\Enums\ErrorCode;
use App\Enums\ShipmentMethod;
use App\Enums\StoreOrderStatus;
use App\Exceptions\ApiException;
use App\Models\Shipment;
use App\Models\StoreOrder;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Scoped lookups and lists of store orders and deliveries (`slice-9b-contract.md` §6.4, §8.2). A foreign or hidden
 * id is a 404.
 */
class StoreOrderQueries
{
    /** Client list: placed orders only (D212/Q115), newest first. */
    public function forClient(User $client, ?string $filter, int $perPage): LengthAwarePaginator
    {
        $statuses = match ($filter) {
            'active' => StoreOrderStatus::active(),
            'done' => StoreOrderStatus::done(),
            default => [...StoreOrderStatus::active(), ...StoreOrderStatus::done()],
        };

        return StoreOrder::query()->where('user_id', $client->id)
            ->whereIn('status', array_map(fn (StoreOrderStatus $s) => $s->value, $statuses))
            ->with($this->clientRelations())
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate($perPage)->withQueryString();
    }

    /** Any of her orders, including unpaid / expired (the app polls it after paying). */
    public function clientOrder(User $client, int $id): StoreOrder
    {
        return StoreOrder::query()->where('user_id', $client->id)->with($this->clientRelations())->find($id)
            ?? throw new ApiException(ErrorCode::NotFound);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function forAdmin(?string $q, array $filters, ?string $sort, int $perPage): LengthAwarePaginator
    {
        $status = $filters['status'] ?? null;
        $direction = str_starts_with((string) $sort, '-') || $sort === null ? 'desc' : 'asc';
        $column = ltrim((string) ($sort ?? '-created_at'), '-') === 'total' ? 'total' : 'created_at';

        return StoreOrder::query()
            ->when($status === null, fn (Builder $query) => $query->whereNotIn('status', [StoreOrderStatus::PendingPayment->value, StoreOrderStatus::Expired->value]))
            ->when($status !== null && $status !== 'all', fn (Builder $query) => $query->where('status', $status))
            ->when($filters['method'] ?? null, fn (Builder $query, string $method) => $query->whereHas('latestShipment', fn (Builder $s) => $s->where('method', $method)))
            ->when($filters['from'] ?? null, fn (Builder $query, string $from) => $query->where('created_at', '>=', $from.' 00:00:00'))
            ->when($filters['to'] ?? null, fn (Builder $query, string $to) => $query->where('created_at', '<=', $to.' 23:59:59'))
            ->when($q, function (Builder $query, string $text) {
                $like = '%'.addcslashes($text, '\\%_').'%';
                $digits = preg_replace('/\D+/', '', $text);
                $query->where(fn (Builder $w) => $w->where('reference', 'like', $like)
                    ->when(strlen((string) $digits) >= 4, fn (Builder $p) => $p->orWhereHas('user', fn (Builder $u) => $u->where('phone', 'like', '%'.$digits.'%'))));
            })
            ->with(['user', 'latestShipment.assignee'])
            ->withSum('items as items_count', 'quantity')
            ->orderBy($column, $direction)->orderBy('id', $direction)
            ->paginate($perPage)->withQueryString();
    }

    public function adminOrder(int $id): StoreOrder
    {
        return StoreOrder::query()->with([
            'user', 'items.variant.product.image', 'shipments.assignee', 'shipments.events.actor', 'canceller',
            'activity' => fn ($a) => $a->with('actor')->orderBy('id'),
        ])->withSum('items as items_count', 'quantity')->find($id) ?? throw new ApiException(ErrorCode::NotFound);
    }

    public function shipment(int $id): Shipment
    {
        return Shipment::query()->find($id) ?? throw new ApiException(ErrorCode::NotFound);
    }

    /** Internal shipments assigned to this officer (`delivery.assigned`). */
    public function deliveriesFor(User $officer, ?string $status, int $perPage): LengthAwarePaginator
    {
        return Shipment::query()
            ->where('method', ShipmentMethod::Internal)->where('assigned_admin_id', $officer->id)
            ->when($status, fn (Builder $query, string $s) => $query->where('status', $s))
            ->with('order.items')
            ->orderByDesc('id')
            ->paginate($perPage)->withQueryString();
    }

    /** @return array<int|string, mixed> */
    private function clientRelations(): array
    {
        return [
            'items.variant.product.image', 'latestShipment',
            'activity' => fn ($a) => $a->where('visibility', 'all')->whereNotNull('to_status')->orderBy('id'),
        ];
    }
}
