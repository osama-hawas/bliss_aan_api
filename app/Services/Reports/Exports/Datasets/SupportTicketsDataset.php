<?php

namespace App\Services\Reports\Exports\Datasets;

use App\Services\Reports\Exports\Cell;
use App\Services\Reports\Exports\ReportDataset;
use App\Services\Reports\ReportScope;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Support tickets (`support.view`). No message bodies (D323).
 */
class SupportTicketsDataset extends ReportDataset
{
    public function key(): string
    {
        return 'support-tickets';
    }

    public function permissions(): array
    {
        return ['support.view'];
    }

    public function tables(): array
    {
        return ['support_tickets'];
    }

    public function columns(): array
    {
        return ['reference', 'client', 'topic', 'subject', 'status', 'assigned_to', 'last_message_at', 'created_at'];
    }

    public function query(ReportScope $scope, array $filters): Builder
    {
        $query = DB::table('support_tickets')
            ->leftJoin('users as owner', 'owner.id', '=', 'support_tickets.user_id')
            ->leftJoin('users as staff', 'staff.id', '=', 'support_tickets.assigned_to')
            ->select(['support_tickets.id', 'support_tickets.reference', 'owner.name as owner_name', 'support_tickets.topic', 'support_tickets.subject',
                'support_tickets.status', 'staff.name as assigned_name', 'support_tickets.last_message_at', 'support_tickets.created_at'])
            ->when($filters['status'] ?? null, fn (Builder $q, string $status) => $q->where('support_tickets.status', $status))
            ->orderByDesc('support_tickets.created_at')->orderByDesc('support_tickets.id');

        return $scope->applyPeriod($query, 'support_tickets.created_at');
    }

    public function map(object $row): array
    {
        return [
            $row->reference, $row->owner_name, __('enums.ticket_topic.'.$row->topic), $row->subject, Cell::status($row->status), $row->assigned_name,
            Cell::dateTime($row->last_message_at), Cell::dateTime($row->created_at),
        ];
    }
}
