<?php

namespace App\Services\Reports\Sources;

use App\Enums\CancellationStatus;
use Illuminate\Database\Query\Builder;

/** Cancellation requests per status (`cancellations.decide` or `refunds.process`). No city (D316). */
class CancellationsSource extends StatusKpiSource
{
    public function key(): string
    {
        return 'cancellations';
    }

    public function permissions(): array
    {
        return ['cancellations.decide', 'refunds.process'];
    }

    protected function table(): string
    {
        return 'cancellation_requests';
    }

    protected function statuses(): array
    {
        return CancellationStatus::values();
    }

    protected function listPath(): string
    {
        return '/cancellations';
    }

    protected function selectRecordColumns(Builder $query): Builder
    {
        return $query
            ->leftJoin('users as requester', 'requester.id', '=', 'cancellation_requests.requester_id')
            ->addSelect(['cancellation_requests.reference', 'requester.name as party_name']);
    }

    protected function describe(object $row): array
    {
        return ['reference' => $row->reference, 'party' => $row->party_name];
    }

    protected function detailHref(object $row): string
    {
        return "/cancellations/{$row->id}";
    }
}
