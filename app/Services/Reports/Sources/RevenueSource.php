<?php

namespace App\Services\Reports\Sources;

use App\Services\Reports\Cents;
use App\Services\Reports\KpiSource;
use App\Services\Reports\ReportScope;
use App\Services\Reports\StatusLabels;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Money in and out of the gateway in the period (D329, `finance.view`): collected = succeeded payments, refunded =
 * settled refunds, net = collected − refunded. Store and booking payments together; no city.
 */
class RevenueSource implements KpiSource
{
    public function __construct(
        private readonly PaymentsSource $payments,
        private readonly RefundsSource $refunds,
    ) {}

    public function key(): string
    {
        return 'revenue';
    }

    public function permissions(): array
    {
        return ['finance.view'];
    }

    public function available(): bool
    {
        return Schema::hasTable('payments') && Schema::hasTable('refunds');
    }

    public function cityAware(): bool
    {
        return false;
    }

    public function hasItem(string $key): bool
    {
        return in_array($key, ['collected', 'refunded'], true);
    }

    public function items(ReportScope $scope): array
    {
        $unavailable = $scope->hasCity();

        $collected = $unavailable ? null : $scope->applyPeriod(DB::table('payments'), 'payments.created_at')
            ->where('status', 'succeeded')
            ->selectRaw('COUNT(*) as aggregate, '.Cents::sumOfDecimal('payments.amount').' as cents')
            ->first();
        $refunded = $unavailable ? null : $scope->applyPeriod(DB::table('refunds'), 'refunds.created_at')
            ->where('status', 'refunded')
            ->selectRaw('COUNT(*) as aggregate, '.Cents::sumOfDecimal('refunds.amount').' as cents')
            ->first();

        return [
            [
                'key' => 'collected',
                'label' => StatusLabels::label('collected'),
                'value' => $collected ? (int) $collected->aggregate : null,
                'amount' => $collected ? Cents::money((int) $collected->cents) : null,
                'href' => '/finance/payments'.$scope->query(['status' => 'succeeded']),
            ],
            [
                'key' => 'refunded',
                'label' => StatusLabels::label('refunded'),
                'value' => $refunded ? (int) $refunded->aggregate : null,
                'amount' => $refunded ? Cents::money((int) $refunded->cents) : null,
                'href' => '/finance/refunds'.$scope->query(['status' => 'refunded']),
            ],
            [
                'key' => 'net',
                'label' => StatusLabels::label('net'),
                'value' => null,
                'amount' => $collected && $refunded ? Cents::money((int) $collected->cents - (int) $refunded->cents) : null,
                'href' => '/finance/payments'.$scope->query(),
            ],
        ];
    }

    public function records(string $key, ReportScope $scope, int $perPage): LengthAwarePaginator
    {
        return match ($key) {
            'collected' => $this->payments->records('succeeded', $scope, $perPage),
            'refunded' => $this->refunds->records('refunded', $scope, $perPage),
            default => throw new NotFoundHttpException,
        };
    }
}
