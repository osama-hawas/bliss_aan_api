<?php

namespace App\Services\Reports\Sources;

use App\Services\Reports\Cents;
use Illuminate\Database\Query\Builder;

/** Payout requests per status with amounts (`finance.view` or `payouts.process`). Reads S11's `payout_requests`. */
class PayoutsSource extends StatusKpiSource
{
    public const STATUSES = ['requested', 'paid', 'rejected'];

    public function key(): string
    {
        return 'payouts';
    }

    public function permissions(): array
    {
        return ['finance.view', 'payouts.process'];
    }

    protected function table(): string
    {
        return 'payout_requests';
    }

    protected function statuses(): array
    {
        return self::STATUSES;
    }

    protected function listScopeKeys(): array
    {
        return ['from', 'to'];
    }

    protected function listPath(): string
    {
        return '/finance/payouts';
    }

    protected function amountCentsSql(): ?string
    {
        return Cents::sumOfCents('payout_requests.amount_cents');
    }

    protected function selectRecordColumns(Builder $query): Builder
    {
        return $query
            ->leftJoin('providers', 'providers.id', '=', 'payout_requests.provider_id')
            ->addSelect(['payout_requests.reference', 'payout_requests.amount_cents', 'providers.display_name as party_name']);
    }

    protected function describe(object $row): array
    {
        return ['reference' => $row->reference, 'party' => $row->party_name];
    }

    protected function detailHref(object $row): string
    {
        return "/finance/payouts/{$row->id}";
    }
}
