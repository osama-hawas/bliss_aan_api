<?php

namespace App\Services\Reports;

use Illuminate\Pagination\LengthAwarePaginator;

/**
 * One KPI group on the indicators page (D316–D319). Each source reads its own tables only and is listed once in
 * KpiSourceRegistry, so a source for a slice that merges later is added or removed without touching the others.
 */
interface KpiSource
{
    /** Group key: `indicators.groups.<key>` in the dashboard. */
    public function key(): string;

    /** @return list<string> any one of these permissions shows the group (D317) */
    public function permissions(): array;

    /** False while a table it reads does not exist yet (slice not merged, D318). */
    public function available(): bool;

    /** False when the source has no city: values are null while a city is chosen (D316). */
    public function cityAware(): bool;

    /**
     * @return list<array{key: string, label: string, value: ?int, amount: ?array{amount: string, currency: string}, href: string}>
     */
    public function items(ReportScope $scope): array;

    public function hasItem(string $key): bool;

    /**
     * The records behind one item (drill-down, D319), newest first.
     *
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function records(string $key, ReportScope $scope, int $perPage): LengthAwarePaginator;
}
