<?php

namespace App\Services\Reports\Sources;

use App\Services\Reports\Cents;
use App\Services\Reports\KpiSource;
use App\Services\Reports\ReportScope;
use App\Services\Reports\StatusLabels;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A KPI group made of "how many records are in each status" (D316), optionally with the money total per status.
 * Uses the query builder and plain status strings only, so it never loads a class of another slice (D318).
 */
abstract class StatusKpiSource implements KpiSource
{
    /** Main table; `created_at` and `status` are read from it. */
    abstract protected function table(): string;

    /** @return list<string> the statuses shown, in display order */
    abstract protected function statuses(): array;

    /** Dashboard list page the items link to (`/requests`). */
    abstract protected function listPath(): string;

    /** Dashboard detail page of one record. */
    abstract protected function detailHref(object $row): string;

    /** Adds the columns `record()` reads (the main table's id/status/created_at are always selected). */
    abstract protected function selectRecordColumns(Builder $query): Builder;

    /** `reference` and `party` of one drill-down row. */
    abstract protected function describe(object $row): array;

    /** @return list<string> every table the source reads */
    protected function tables(): array
    {
        return [$this->table()];
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

    public function cityAware(): bool
    {
        return false;
    }

    /** Limits the query to one city; only called when cityAware(). */
    protected function applyCity(Builder $query, int $cityId): void {}

    /** Base rows of the source (e.g. `users` where type = client). */
    protected function base(): Builder
    {
        return DB::table($this->table());
    }

    /** SQL of the per-status money total in cents (Cents::sumOf…), or null when the source has no amount. */
    protected function amountCentsSql(): ?string
    {
        return null;
    }

    /** Cents of one drill-down row, from the selected `amount_cents` column. */
    protected function rowCents(object $row): ?int
    {
        return isset($row->amount_cents) ? (int) $row->amount_cents : null;
    }

    public function hasItem(string $key): bool
    {
        return in_array($key, $this->statuses(), true);
    }

    protected function scoped(ReportScope $scope): Builder
    {
        $query = $this->base();
        $scope->applyPeriod($query, $this->table().'.created_at');

        if ($scope->hasCity() && $this->cityAware()) {
            $this->applyCity($query, $scope->cityId);
        }

        return $query;
    }

    public function items(ReportScope $scope): array
    {
        $unavailable = $scope->hasCity() && ! $this->cityAware();
        $table = $this->table();
        $amountSql = $this->amountCentsSql();

        $counts = $unavailable ? collect() : $this->scoped($scope)
            ->selectRaw("{$table}.status as status, COUNT(*) as aggregate".($amountSql ? ", {$amountSql} as cents" : ''))
            ->whereIn("{$table}.status", $this->statuses())
            ->groupBy("{$table}.status")
            ->get()
            ->keyBy('status');

        return array_map(fn (string $status) => [
            'key' => $status,
            'label' => StatusLabels::label($status),
            'value' => $unavailable ? null : (int) ($counts[$status]->aggregate ?? 0),
            'amount' => $amountSql === null || $unavailable ? null : Cents::money((int) ($counts[$status]->cents ?? 0)),
            'href' => $this->listPath().$scope->query(['status' => $status]),
        ], $this->statuses());
    }

    public function records(string $key, ReportScope $scope, int $perPage): LengthAwarePaginator
    {
        if (! $this->hasItem($key) || ($scope->hasCity() && ! $this->cityAware())) {
            throw new NotFoundHttpException;
        }

        $table = $this->table();
        $query = $this->scoped($scope)
            ->where("{$table}.status", $key)
            ->select(["{$table}.id", "{$table}.status", "{$table}.created_at"])
            ->orderByDesc("{$table}.created_at")
            ->orderByDesc("{$table}.id");

        return $this->selectRecordColumns($query)
            ->paginate($perPage)
            ->through(fn (object $row) => $this->record($row));
    }

    /**
     * @return array{id: int, reference: ?string, status: string, status_label: string, date: ?string, city: ?array, party: ?string, amount: ?array, href: string}
     */
    protected function record(object $row): array
    {
        $described = $this->describe($row);

        return [
            'id' => (int) $row->id,
            'reference' => $described['reference'] ?? null,
            'status' => $row->status,
            'status_label' => StatusLabels::label($row->status),
            'date' => $row->created_at ? Carbon::parse($row->created_at, 'UTC')->toIso8601ZuluString() : null,
            'city' => isset($row->city_name) ? json_decode($row->city_name, true) : null,
            'party' => $described['party'] ?? null,
            'amount' => Cents::money($this->rowCents($row)),
            'href' => $this->detailHref($row),
        ];
    }
}
