<?php

namespace App\Services\Reports\Exports\Datasets;

use App\Services\Reports\Exports\Cell;
use App\Services\Reports\Exports\ReportDataset;
use App\Services\Reports\ReportScope;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Refunds (`finance.view` or `refunds.process`), S10's `refunds` table.
 */
class RefundsDataset extends ReportDataset
{
    public function key(): string
    {
        return 'refunds';
    }

    public function permissions(): array
    {
        return ['finance.view', 'refunds.process'];
    }

    public function tables(): array
    {
        return ['refunds', 'payments'];
    }

    public function columns(): array
    {
        return ['reference', 'payment', 'kind', 'amount', 'reason', 'status', 'initiated_by', 'processed_at', 'created_at'];
    }

    public function query(ReportScope $scope, array $filters): Builder
    {
        $query = DB::table('refunds')
            ->leftJoin('payments', 'payments.id', '=', 'refunds.payment_id')
            ->leftJoin('users as staff', 'staff.id', '=', 'refunds.initiated_by')
            ->select(['refunds.id', 'refunds.reference', 'payments.reference as payment_reference', 'refunds.kind', 'refunds.amount', 'refunds.reason', 'refunds.status',
                'staff.name as staff_name', 'refunds.processed_at', 'refunds.created_at'])
            ->when($filters['status'] ?? null, fn (Builder $q, string $status) => $q->where('refunds.status', $status))
            ->orderByDesc('refunds.created_at')->orderByDesc('refunds.id');

        return $scope->applyPeriod($query, 'refunds.created_at');
    }

    public function map(object $row): array
    {
        return [
            $row->reference, $row->payment_reference, Cell::status($row->kind), Cell::money($row->amount), $row->reason, Cell::status($row->status),
            $row->staff_name ?? __('reports.system'), Cell::dateTime($row->processed_at), Cell::dateTime($row->created_at),
        ];
    }
}
