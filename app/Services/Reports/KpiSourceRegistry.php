<?php

namespace App\Services\Reports;

use App\Models\User;
use App\Services\Reports\Sources\ApplicationsSource;
use App\Services\Reports\Sources\BookingsSource;
use App\Services\Reports\Sources\CancellationsSource;
use App\Services\Reports\Sources\ClientsSource;
use App\Services\Reports\Sources\PaymentsSource;
use App\Services\Reports\Sources\PayoutsSource;
use App\Services\Reports\Sources\ProvidersSource;
use App\Services\Reports\Sources\RefundsSource;
use App\Services\Reports\Sources\RequestsSource;
use App\Services\Reports\Sources\RevenueSource;
use App\Services\Reports\Sources\StoreOrdersSource;
use App\Services\Reports\Sources\SupportSource;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Every KPI group, in display order (D318). A later slice adds its source with one line here.
 */
class KpiSourceRegistry
{
    /** @var list<class-string<KpiSource>> */
    public const SOURCES = [
        RequestsSource::class,
        BookingsSource::class,
        CancellationsSource::class,
        SupportSource::class,
        ClientsSource::class,
        ProvidersSource::class,
        ApplicationsSource::class,
        // Sources over tables of S9b / S10 / S11: absent until those slices are merged.
        StoreOrdersSource::class,
        PaymentsSource::class,
        RefundsSource::class,
        RevenueSource::class,
        PayoutsSource::class,
    ];

    /**
     * Sources whose tables exist and that the staff member may see (D317).
     *
     * @return list<KpiSource>
     */
    public function visibleTo(User $staff): array
    {
        $sources = array_map(fn (string $class) => app($class), self::SOURCES);

        return array_values(array_filter($sources, fn (KpiSource $source) => $source->available() && $this->allows($staff, $source)));
    }

    /**
     * One visible source by group key, or 404 (missing, unavailable or not permitted — never leak which).
     */
    public function find(User $staff, string $key): KpiSource
    {
        foreach ($this->visibleTo($staff) as $source) {
            if ($source->key() === $key) {
                return $source;
            }
        }

        throw new NotFoundHttpException;
    }

    public function allows(User $staff, KpiSource $source): bool
    {
        return StaffAccess::any($staff, $source->permissions());
    }
}
