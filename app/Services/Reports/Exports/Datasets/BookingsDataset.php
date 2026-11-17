<?php

namespace App\Services\Reports\Exports\Datasets;

use App\Services\Reports\Exports\Cell;
use App\Services\Reports\Exports\ReportDataset;
use App\Services\Reports\ReportScope;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Bookings (`bookings.view`). No address snapshot (D323).
 */
class BookingsDataset extends ReportDataset
{
    public function key(): string
    {
        return 'bookings';
    }

    public function permissions(): array
    {
        return ['bookings.view'];
    }

    public function tables(): array
    {
        return ['bookings'];
    }

    public function columns(): array
    {
        return ['reference', 'client', 'provider', 'service', 'city', 'starts_at', 'total', 'status', 'payment_status', 'created_at'];
    }

    public function query(ReportScope $scope, array $filters): Builder
    {
        $query = DB::table('bookings')
            ->join('service_requests as sr', 'sr.id', '=', 'bookings.service_request_id')
            ->leftJoin('users as client', 'client.id', '=', 'bookings.client_id')
            ->leftJoin('providers', 'providers.id', '=', 'bookings.provider_id')
            ->leftJoin('services', 'services.id', '=', 'bookings.service_id')
            ->leftJoin('cities', 'cities.id', '=', 'sr.city_id')
            ->select(['bookings.id', 'bookings.reference', 'client.name as client_name', 'providers.display_name as provider_name', 'services.name as service_name',
                'cities.name as city_name', 'bookings.starts_at', 'bookings.total', 'bookings.status', 'bookings.payment_status', 'bookings.created_at'])
            ->when($filters['status'] ?? null, fn (Builder $q, string $status) => $q->where('bookings.status', $status))
            ->when($scope->cityId, fn (Builder $q, int $city) => $q->where('sr.city_id', $city))
            ->orderByDesc('bookings.created_at')->orderByDesc('bookings.id');

        return $scope->applyPeriod($query, 'bookings.created_at');
    }

    public function map(object $row): array
    {
        return [
            $row->reference, $row->client_name, $row->provider_name, Cell::translated($row->service_name), Cell::translated($row->city_name),
            Cell::dateTime($row->starts_at), Cell::money($row->total), Cell::status($row->status), Cell::status($row->payment_status), Cell::dateTime($row->created_at),
        ];
    }
}
