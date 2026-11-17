<?php

namespace App\Services\Reports\Exports\Datasets;

use App\Services\Reports\Exports\Cell;
use App\Services\Reports\Exports\ReportDataset;
use App\Services\Reports\ReportScope;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Store orders (`orders.view`), S9b's `store_orders`. No recipient phone or address details (D323); unpaid attempts left out.
 */
class StoreOrdersDataset extends ReportDataset
{
    public function key(): string
    {
        return 'store-orders';
    }

    public function permissions(): array
    {
        return ['orders.view'];
    }

    public function tables(): array
    {
        return ['store_orders'];
    }

    public function columns(): array
    {
        return ['reference', 'client', 'city', 'subtotal', 'discount', 'shipping_fee', 'total', 'vat_amount', 'status', 'confirmed_at', 'delivered_at', 'created_at'];
    }

    public function query(ReportScope $scope, array $filters): Builder
    {
        $query = DB::table('store_orders')
            ->where('store_orders.status', '!=', 'pending_payment')
            ->leftJoin('users as client', 'client.id', '=', 'store_orders.user_id')
            ->select(['store_orders.id', 'store_orders.reference', 'client.name as client_name', 'store_orders.subtotal', 'store_orders.discount', 'store_orders.shipping_fee',
                'store_orders.total', 'store_orders.vat_amount', 'store_orders.status', 'store_orders.confirmed_at', 'store_orders.delivered_at', 'store_orders.created_at'])
            ->selectRaw("JSON_EXTRACT(store_orders.address, '\$.city') as city_name")
            ->when($filters['status'] ?? null, fn (Builder $q, string $status) => $q->where('store_orders.status', $status))
            ->when($scope->cityId, fn (Builder $q, int $city) => $q->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(store_orders.address, '\$.city_id')) = ?", [(string) $city]))
            ->orderByDesc('store_orders.created_at')->orderByDesc('store_orders.id');

        return $scope->applyPeriod($query, 'store_orders.created_at');
    }

    public function map(object $row): array
    {
        return [
            $row->reference, $row->client_name, Cell::translated($row->city_name), Cell::money($row->subtotal), Cell::money($row->discount), Cell::money($row->shipping_fee),
            Cell::money($row->total), Cell::money($row->vat_amount), Cell::status($row->status), Cell::dateTime($row->confirmed_at), Cell::dateTime($row->delivered_at), Cell::dateTime($row->created_at),
        ];
    }
}
