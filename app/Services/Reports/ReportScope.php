<?php

namespace App\Services\Reports;

use App\Support\RiyadhCalendar;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;

/**
 * The period and city a KPI, drill-down or export is limited to (D316). Dates are Riyadh dates, inclusive; the
 * period applies to the record's `created_at` (UTC). A city applies only to sources that have one.
 */
final readonly class ReportScope
{
    public function __construct(
        public ?string $from = null,
        public ?string $to = null,
        public ?int $cityId = null,
    ) {}

    /**
     * @param  array{from?: ?string, to?: ?string, city_id?: int|string|null}  $filters
     */
    public static function fromFilters(array $filters): self
    {
        $city = $filters['city_id'] ?? null;

        return new self(
            $filters['from'] ?? null ?: null,
            $filters['to'] ?? null ?: null,
            $city === null || $city === '' ? null : (int) $city,
        );
    }

    public function start(): ?Carbon
    {
        return $this->from ? RiyadhCalendar::dayStart($this->from) : null;
    }

    public function end(): ?Carbon
    {
        return $this->to ? RiyadhCalendar::dayEnd($this->to) : null;
    }

    /**
     * Half-open [from 00:00, to + 1 day 00:00) Riyadh on the given column.
     */
    public function applyPeriod(Builder $query, string $column): Builder
    {
        return $query
            ->when($this->start(), fn (Builder $q, Carbon $start) => $q->where($column, '>=', $start))
            ->when($this->end(), fn (Builder $q, Carbon $end) => $q->where($column, '<', $end));
    }

    public function hasCity(): bool
    {
        return $this->cityId !== null;
    }

    /**
     * Query string for drill-down links: `status=…&from=…&to=…&city_id=…` (dashboard URL state keys). `$only` = the scope
     * keys the target list page reads, so a link never carries a filter the page silently ignores (review L4).
     *
     * @param  array<string, string|int|null>  $extra
     * @param  list<string>  $only
     */
    public function query(array $extra = [], array $only = ['from', 'to', 'city_id']): string
    {
        $scope = array_intersect_key(['from' => $this->from, 'to' => $this->to, 'city_id' => $this->cityId], array_flip($only));
        $params = array_filter([...$extra, ...$scope], fn ($value) => $value !== null && $value !== '');

        return $params ? '?'.http_build_query($params) : '';
    }

    /**
     * @return array{from: ?string, to: ?string, city_id: ?int}
     */
    public function toArray(): array
    {
        return ['from' => $this->from, 'to' => $this->to, 'city_id' => $this->cityId];
    }
}
