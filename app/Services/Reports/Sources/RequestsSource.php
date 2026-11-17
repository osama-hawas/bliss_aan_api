<?php

namespace App\Services\Reports\Sources;

use App\Enums\ServiceRequestStatus;
use Illuminate\Database\Query\Builder;

/** Service requests per status (`bookings.view`, Req 56). City = the request's city. */
class RequestsSource extends StatusKpiSource
{
    public function key(): string
    {
        return 'requests';
    }

    public function permissions(): array
    {
        return ['bookings.view'];
    }

    protected function table(): string
    {
        return 'service_requests';
    }

    protected function statuses(): array
    {
        return ServiceRequestStatus::values();
    }

    protected function listPath(): string
    {
        return '/requests';
    }

    public function cityAware(): bool
    {
        return true;
    }

    protected function applyCity(Builder $query, int $cityId): void
    {
        $query->where('service_requests.city_id', $cityId);
    }

    protected function selectRecordColumns(Builder $query): Builder
    {
        return $query
            ->leftJoin('cities', 'cities.id', '=', 'service_requests.city_id')
            ->leftJoin('users as client', 'client.id', '=', 'service_requests.client_id')
            ->addSelect(['service_requests.reference', 'cities.name as city_name', 'client.name as party_name']);
    }

    protected function describe(object $row): array
    {
        return ['reference' => $row->reference, 'party' => $row->party_name];
    }

    protected function detailHref(object $row): string
    {
        return "/requests/{$row->id}";
    }
}
