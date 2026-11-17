<?php

namespace App\Services\Reports\Sources;

use App\Enums\TicketStatus;
use Illuminate\Database\Query\Builder;

/** Support tickets per status (`support.view`). No city (D316). */
class SupportSource extends StatusKpiSource
{
    public function key(): string
    {
        return 'support';
    }

    public function permissions(): array
    {
        return ['support.view'];
    }

    protected function table(): string
    {
        return 'support_tickets';
    }

    protected function statuses(): array
    {
        return TicketStatus::values();
    }

    protected function listPath(): string
    {
        return '/support';
    }

    protected function selectRecordColumns(Builder $query): Builder
    {
        return $query
            ->leftJoin('users as owner', 'owner.id', '=', 'support_tickets.user_id')
            ->addSelect(['support_tickets.reference', 'owner.name as party_name']);
    }

    protected function describe(object $row): array
    {
        return ['reference' => $row->reference, 'party' => $row->party_name];
    }

    protected function detailHref(object $row): string
    {
        return "/support/{$row->id}";
    }
}
