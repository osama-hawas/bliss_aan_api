<?php

namespace App\Services\Reports\Sources;

use App\Services\Reports\Cents;
use Illuminate\Database\Query\Builder;

/** Refunds per status with amounts (`finance.view` or `refunds.process`). Reads S10's `refunds`; no city. */
class RefundsSource extends StatusKpiSource
{
    public const STATUSES = ['in_progress', 'refunded', 'failed'];

    public function key(): string
    {
        return 'refunds';
    }

    public function permissions(): array
    {
        return ['finance.view', 'refunds.process'];
    }

    protected function table(): string
    {
        return 'refunds';
    }

    protected function tables(): array
    {
        return ['refunds', 'payments'];
    }

    protected function statuses(): array
    {
        return self::STATUSES;
    }

    protected function listPath(): string
    {
        return '/finance/refunds';
    }

    protected function amountCentsSql(): ?string
    {
        return Cents::sumOfDecimal('refunds.amount');
    }

    protected function selectRecordColumns(Builder $query): Builder
    {
        return $query
            ->leftJoin('payments', 'payments.id', '=', 'refunds.payment_id')
            ->leftJoin('users as payer', 'payer.id', '=', 'payments.user_id')
            ->addSelect(['refunds.reference', 'refunds.payment_id', 'payer.name as party_name'])
            ->selectRaw('ROUND(refunds.amount * 100) as amount_cents');
    }

    protected function describe(object $row): array
    {
        return ['reference' => $row->reference, 'party' => $row->party_name];
    }

    protected function detailHref(object $row): string
    {
        return "/finance/payments/{$row->payment_id}";
    }
}
