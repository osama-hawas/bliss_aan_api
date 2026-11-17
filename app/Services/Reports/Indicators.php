<?php

namespace App\Services\Reports;

use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The indicators page (D316–D319, D329): every visible KPI group within the scope, and the records behind one item.
 */
class Indicators
{
    public function __construct(private readonly KpiSourceRegistry $registry) {}

    /**
     * @return list<array{key: string, city_aware: bool, items: list<array<string, mixed>>}>
     */
    public function groups(User $staff, ReportScope $scope): array
    {
        return array_map(fn (KpiSource $source) => [
            'key' => $source->key(),
            'city_aware' => $source->cityAware(),
            'items' => $source->items($scope),
        ], $this->registry->visibleTo($staff));
    }

    public function records(User $staff, string $group, string $key, ReportScope $scope, int $perPage): LengthAwarePaginator
    {
        $source = $this->registry->find($staff, $group);

        if (! $source->hasItem($key)) {
            throw new NotFoundHttpException;
        }

        return $source->records($key, $scope, $perPage);
    }
}
