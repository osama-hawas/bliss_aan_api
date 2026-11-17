<?php

namespace App\Services\Reports\Sources;

use App\Enums\ProviderStatus;
use Illuminate\Database\Query\Builder;

/** Activated providers per status (`providers.view`). City = her city. */
class ProvidersSource extends StatusKpiSource
{
    public function key(): string
    {
        return 'providers';
    }

    public function permissions(): array
    {
        return ['providers.view'];
    }

    protected function table(): string
    {
        return 'providers';
    }

    protected function statuses(): array
    {
        return [ProviderStatus::Active->value, ProviderStatus::Suspended->value];
    }

    protected function listPath(): string
    {
        return '/providers';
    }

    public function cityAware(): bool
    {
        return true;
    }

    protected function applyCity(Builder $query, int $cityId): void
    {
        $query->where('providers.city_id', $cityId);
    }

    protected function selectRecordColumns(Builder $query): Builder
    {
        return $query
            ->leftJoin('cities', 'cities.id', '=', 'providers.city_id')
            ->leftJoin('users as owner', 'owner.id', '=', 'providers.user_id')
            ->addSelect(['providers.display_name', 'owner.phone', 'cities.name as city_name']);
    }

    protected function describe(object $row): array
    {
        return ['reference' => $row->phone, 'party' => $row->display_name];
    }

    protected function detailHref(object $row): string
    {
        return "/providers/{$row->id}";
    }
}
