<?php

namespace App\Services\Reports\Exports;

use App\Services\Reports\ReportScope;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Facades\Schema;

/**
 * One exportable list (D322). Rows come from a query over the dataset's own tables only (D318) and carry only what
 * the module list shows to the same permission (D323).
 */
abstract class ReportDataset
{
    /** URL segment: `admin/exports/{key}`. */
    abstract public function key(): string;

    /** @return list<string> any one of these (on top of `reports.export`) */
    abstract public function permissions(): array;

    /** @return list<string> column keys (`reports.columns.<key>` headings) */
    abstract public function columns(): array;

    /**
     * @param  array{status?: ?string, q?: ?string}  $filters
     */
    abstract public function query(ReportScope $scope, array $filters): Builder;

    /** @return list<mixed> one sheet row, in `columns()` order */
    abstract public function map(object $row): array;

    /** @return list<string> */
    public function tables(): array
    {
        return [];
    }

    public function available(): bool
    {
        foreach ($this->tables() as $table) {
            if (! Schema::hasTable($table)) {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> extra `filter[...]` keys this dataset accepts besides from/to/city_id/status */
    public function extraFilters(): array
    {
        return [];
    }

    /** @return list<string> */
    public function headings(): array
    {
        return array_map(fn (string $column) => __("reports.columns.{$column}"), $this->columns());
    }

    public function title(): string
    {
        return __("reports.datasets.{$this->key()}");
    }

    /** Download name, e.g. `bookings-2026-11-17.xlsx`. */
    public function fileName(): string
    {
        return $this->key().'-'.now('Asia/Riyadh')->format('Y-m-d-His').'.xlsx';
    }
}
