<?php

namespace App\Services\Reports\Exports\Datasets;

use App\Services\Reports\Exports\Cell;
use App\Services\Reports\Exports\ReportDataset;
use App\Services\Reports\ReportScope;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Service requests (`bookings.view`). No address, pin, notes or attachments (D323).
 */
class ServiceRequestsDataset extends ReportDataset
{
    public function key(): string
    {
        return 'service-requests';
    }

    public function permissions(): array
    {
        return ['bookings.view'];
    }

    public function tables(): array
    {
        return ['service_requests'];
    }

    public function columns(): array
    {
        return ['reference', 'type', 'client', 'service', 'city', 'starts_at', 'status', 'created_at'];
    }

    public function query(ReportScope $scope, array $filters): Builder
    {
        $query = DB::table('service_requests')
            ->leftJoin('users as client', 'client.id', '=', 'service_requests.client_id')
            ->leftJoin('services', 'services.id', '=', 'service_requests.service_id')
            ->leftJoin('cities', 'cities.id', '=', 'service_requests.city_id')
            ->select(['service_requests.id', 'service_requests.reference', 'service_requests.type', 'client.name as client_name', 'services.name as service_name',
                'cities.name as city_name', 'service_requests.starts_at', 'service_requests.status', 'service_requests.created_at'])
            ->when($filters['status'] ?? null, fn (Builder $q, string $status) => $q->where('service_requests.status', $status))
            ->when($scope->cityId, fn (Builder $q, int $city) => $q->where('service_requests.city_id', $city))
            ->orderByDesc('service_requests.created_at')->orderByDesc('service_requests.id');

        return $scope->applyPeriod($query, 'service_requests.created_at');
    }

    public function map(object $row): array
    {
        return [
            $row->reference, __('enums.service_request_type.'.$row->type), $row->client_name, Cell::translated($row->service_name), Cell::translated($row->city_name),
            Cell::dateTime($row->starts_at), Cell::status($row->status), Cell::dateTime($row->created_at),
        ];
    }
}
