<?php

namespace App\Services\Reports\Exports\Datasets;

use App\Services\Reports\Exports\Cell;
use App\Services\Reports\Exports\ReportDataset;
use App\Services\Reports\ReportScope;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Client accounts (`clients.view`). No address, birth date or device data (D323).
 */
class ClientsDataset extends ReportDataset
{
    public function key(): string
    {
        return 'clients';
    }

    public function permissions(): array
    {
        return ['clients.view'];
    }

    public function tables(): array
    {
        return ['users'];
    }

    public function columns(): array
    {
        return ['id', 'name', 'phone', 'email', 'city', 'status', 'requests_count', 'bookings_count', 'created_at', 'last_login_at'];
    }

    public function query(ReportScope $scope, array $filters): Builder
    {
        $query = DB::table('users')
            ->where('users.type', 'client')
            ->leftJoin('cities', 'cities.id', '=', 'users.city_id')
            ->select(['users.id', 'users.name', 'users.phone', 'users.email', 'users.status', 'users.created_at', 'users.last_login_at', 'cities.name as city_name'])
            ->selectSub(DB::table('service_requests')->selectRaw('COUNT(*)')->whereColumn('service_requests.client_id', 'users.id'), 'requests_count')
            ->selectSub(DB::table('bookings')->selectRaw('COUNT(*)')->whereColumn('bookings.client_id', 'users.id'), 'bookings_count')
            ->when($filters['status'] ?? null, fn (Builder $q, string $status) => $q->where('users.status', $status))
            ->when($scope->cityId, fn (Builder $q, int $city) => $q->where('users.city_id', $city))
            ->orderByDesc('users.created_at')->orderByDesc('users.id');

        return $scope->applyPeriod($query, 'users.created_at');
    }

    public function map(object $row): array
    {
        return [
            $row->id, $row->name, $row->phone, $row->email, Cell::translated($row->city_name), Cell::status($row->status),
            (int) $row->requests_count, (int) $row->bookings_count, Cell::dateTime($row->created_at), Cell::dateTime($row->last_login_at),
        ];
    }
}
