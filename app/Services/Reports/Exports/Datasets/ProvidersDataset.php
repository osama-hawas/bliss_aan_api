<?php

namespace App\Services\Reports\Exports\Datasets;

use App\Services\Reports\Exports\Cell;
use App\Services\Reports\Exports\ReportDataset;
use App\Services\Reports\ReportScope;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Activated providers (`providers.view`, the S15 roster). No documents, legal or bank data (D323).
 */
class ProvidersDataset extends ReportDataset
{
    public function key(): string
    {
        return 'providers';
    }

    public function permissions(): array
    {
        return ['providers.view'];
    }

    public function tables(): array
    {
        return ['providers'];
    }

    public function columns(): array
    {
        return ['id', 'name', 'account_type', 'phone', 'city', 'status', 'is_verified', 'is_featured', 'rating', 'activated_at', 'created_at'];
    }

    public function query(ReportScope $scope, array $filters): Builder
    {
        $query = DB::table('providers')
            ->whereIn('providers.status', ['active', 'suspended'])
            ->join('users as owner', 'owner.id', '=', 'providers.user_id')
            ->leftJoin('cities', 'cities.id', '=', 'providers.city_id')
            ->select(['providers.id', 'providers.display_name', 'providers.account_type', 'owner.phone', 'cities.name as city_name', 'providers.status',
                'providers.is_verified', 'providers.is_featured', 'providers.rating_avg', 'providers.activated_at', 'providers.created_at'])
            ->when($filters['status'] ?? null, fn (Builder $q, string $status) => $q->where('providers.status', $status))
            ->when($scope->cityId, fn (Builder $q, int $city) => $q->where('providers.city_id', $city))
            ->orderByDesc('providers.activated_at')->orderByDesc('providers.id');

        return $scope->applyPeriod($query, 'providers.created_at');
    }

    public function map(object $row): array
    {
        return [
            $row->id, $row->display_name, __('enums.provider_account_type.'.$row->account_type), $row->phone, Cell::translated($row->city_name), Cell::status($row->status),
            Cell::bool($row->is_verified), Cell::bool($row->is_featured), Cell::number($row->rating_avg), Cell::dateTime($row->activated_at), Cell::dateTime($row->created_at),
        ];
    }
}
