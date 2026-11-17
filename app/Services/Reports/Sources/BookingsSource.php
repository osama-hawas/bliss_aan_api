<?php

namespace App\Services\Reports\Sources;

use App\Enums\BookingStatus;
use App\Services\Reports\Cents;
use Illuminate\Database\Query\Builder;

/** Bookings per status with the booked total (`bookings.view`). City = the city of the booking's request. */
class BookingsSource extends StatusKpiSource
{
    public function key(): string
    {
        return 'bookings';
    }

    public function permissions(): array
    {
        return ['bookings.view'];
    }

    protected function table(): string
    {
        return 'bookings';
    }

    protected function statuses(): array
    {
        return BookingStatus::values();
    }

    protected function listScopeKeys(): array
    {
        return ['from', 'to', 'city_id'];
    }

    protected function listPath(): string
    {
        return '/bookings';
    }

    public function cityAware(): bool
    {
        return true;
    }

    protected function applyCity(Builder $query, int $cityId): void
    {
        $query->whereIn('bookings.service_request_id', fn (Builder $q) => $q->select('id')->from('service_requests')->where('city_id', $cityId));
    }

    protected function amountCentsSql(): ?string
    {
        return Cents::sumOfDecimal('bookings.total');
    }

    protected function selectRecordColumns(Builder $query): Builder
    {
        return $query
            ->join('service_requests as sr', 'sr.id', '=', 'bookings.service_request_id')
            ->leftJoin('cities', 'cities.id', '=', 'sr.city_id')
            ->leftJoin('users as client', 'client.id', '=', 'bookings.client_id')
            ->leftJoin('providers', 'providers.id', '=', 'bookings.provider_id')
            ->addSelect(['bookings.reference', 'cities.name as city_name', 'client.name as client_name', 'providers.display_name as provider_name'])
            ->selectRaw('ROUND(bookings.total * 100) as amount_cents');
    }

    protected function describe(object $row): array
    {
        return ['reference' => $row->reference, 'party' => trim(($row->client_name ?? '').' – '.($row->provider_name ?? ''), ' –')];
    }

    protected function detailHref(object $row): string
    {
        return "/bookings/{$row->id}";
    }
}
