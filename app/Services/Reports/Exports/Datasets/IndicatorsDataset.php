<?php

namespace App\Services\Reports\Exports\Datasets;

use App\Models\City;
use App\Models\User;
use App\Services\Reports\Exports\Cell;
use App\Services\Reports\Exports\ReportDataset;
use App\Services\Reports\Indicators;
use App\Services\Reports\ReportScope;
use App\Services\Reports\StatusLabels;
use Illuminate\Contracts\Database\Query\Builder;
use LogicException;

/**
 * The indicators page as a sheet (`dashboard.view`): one row per KPI item the staff member can see, in the scope.
 */
class IndicatorsDataset extends ReportDataset
{
    public function __construct(private readonly Indicators $indicators) {}

    public function key(): string
    {
        return 'indicators';
    }

    public function permissions(): array
    {
        return ['dashboard.view'];
    }

    public function columns(): array
    {
        return ['group', 'item', 'count', 'amount', 'period', 'city'];
    }

    public function query(ReportScope $scope, array $filters): Builder
    {
        throw new LogicException('Indicators are exported from rows().');
    }

    public function map(object $row): array
    {
        return (array) $row;
    }

    /**
     * @return list<list<mixed>>
     */
    public function rows(User $staff, ReportScope $scope): array
    {
        $period = ($scope->from ?? '…').' – '.($scope->to ?? '…');
        $city = $scope->cityId === null ? __('reports.all') : (City::query()->find($scope->cityId)?->name ?? '#'.$scope->cityId);
        $rows = [];

        foreach ($this->indicators->groups($staff, $scope) as $group) {
            foreach ($group['items'] as $item) {
                $rows[] = [
                    __("reports.groups.{$group['key']}"),
                    StatusLabels::label($item['key']),
                    $item['value'],
                    Cell::money($item['amount']['amount'] ?? null),
                    $period,
                    $city,
                ];
            }
        }

        return $rows;
    }
}
