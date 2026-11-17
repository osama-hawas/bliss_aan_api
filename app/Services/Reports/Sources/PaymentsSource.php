<?php

namespace App\Services\Reports\Sources;

use App\Services\Reports\Cents;
use Illuminate\Database\Query\Builder;

/** Payments per status with amounts (`finance.view`). Reads S10's `payments`; no city (D316, D318). */
class PaymentsSource extends StatusKpiSource
{
    public const STATUSES = ['pending', 'requires_action', 'succeeded', 'failed', 'cancelled', 'unknown'];

    public function key(): string
    {
        return 'payments';
    }

    public function permissions(): array
    {
        return ['finance.view'];
    }

    protected function table(): string
    {
        return 'payments';
    }

    protected function statuses(): array
    {
        return self::STATUSES;
    }

    protected function listPath(): string
    {
        return '/finance/payments';
    }

    protected function amountCentsSql(): ?string
    {
        return Cents::sumOfDecimal('payments.amount');
    }

    protected function selectRecordColumns(Builder $query): Builder
    {
        return $query
            ->leftJoin('users as payer', 'payer.id', '=', 'payments.user_id')
            ->addSelect(['payments.reference', 'payer.name as party_name'])
            ->selectRaw('ROUND(payments.amount * 100) as amount_cents');
    }

    protected function describe(object $row): array
    {
        return ['reference' => $row->reference, 'party' => $row->party_name];
    }

    protected function detailHref(object $row): string
    {
        return "/finance/payments/{$row->id}";
    }
}
