<?php

namespace App\Services\Reports\Sources;

use App\Enums\UserStatus;
use App\Enums\UserType;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Client accounts per status (`clients.view`), period on registration. City = her city. */
class ClientsSource extends StatusKpiSource
{
    public function key(): string
    {
        return 'clients';
    }

    public function permissions(): array
    {
        return ['clients.view'];
    }

    protected function table(): string
    {
        return 'users';
    }

    protected function base(): Builder
    {
        return DB::table('users')->where('users.type', UserType::Client->value);
    }

    protected function statuses(): array
    {
        return UserStatus::values();
    }

    protected function listScopeKeys(): array
    {
        return ['city_id'];
    }

    protected function listPath(): string
    {
        return '/clients';
    }

    public function cityAware(): bool
    {
        return true;
    }

    protected function applyCity(Builder $query, int $cityId): void
    {
        $query->where('users.city_id', $cityId);
    }

    protected function selectRecordColumns(Builder $query): Builder
    {
        return $query
            ->leftJoin('cities', 'cities.id', '=', 'users.city_id')
            ->addSelect(['users.name', 'users.phone', 'cities.name as city_name']);
    }

    protected function describe(object $row): array
    {
        return ['reference' => $row->phone, 'party' => $row->name];
    }

    protected function detailHref(object $row): string
    {
        return "/clients/{$row->id}";
    }
}
