<?php

namespace App\Services\Reports\Sources;

use App\Services\Reports\Cents;
use Illuminate\Database\Query\Builder;

/**
 * Store orders per status with the order total (`orders.view`). Reads S9b's `store_orders` (contract slice-9b):
 * plain status strings, city from the address snapshot (`address->city_id`). Absent until S9b is merged (D318).
 * `pending_payment` is left out: an unpaid attempt is not an order yet (OD10).
 */
class StoreOrdersSource extends StatusKpiSource
{
    public const STATUSES = ['confirmed', 'preparing', 'shipped', 'delivered', 'cancelled', 'expired'];

    public function key(): string
    {
        return 'orders';
    }

    public function permissions(): array
    {
        return ['orders.view'];
    }

    protected function table(): string
    {
        return 'store_orders';
    }

    protected function statuses(): array
    {
        return self::STATUSES;
    }

    protected function listScopeKeys(): array
    {
        return ['from', 'to'];
    }

    protected function listPath(): string
    {
        return '/store/orders';
    }

    public function cityAware(): bool
    {
        return true;
    }

    protected function applyCity(Builder $query, int $cityId): void
    {
        $query->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(store_orders.address, '$.city_id')) = ?", [(string) $cityId]);
    }

    protected function amountCentsSql(): ?string
    {
        return Cents::sumOfDecimal('store_orders.total');
    }

    protected function selectRecordColumns(Builder $query): Builder
    {
        return $query
            ->leftJoin('users as client', 'client.id', '=', 'store_orders.user_id')
            ->addSelect(['store_orders.reference', 'client.name as party_name'])
            ->selectRaw("JSON_EXTRACT(store_orders.address, '$.city') as city_name")
            ->selectRaw('ROUND(store_orders.total * 100) as amount_cents');
    }

    protected function describe(object $row): array
    {
        return ['reference' => $row->reference, 'party' => $row->party_name];
    }

    protected function detailHref(object $row): string
    {
        return "/store/orders/{$row->id}";
    }
}
