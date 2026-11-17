<?php

namespace App\Services\Reports\Exports\Datasets;

use App\Services\Reports\Exports\Cell;
use App\Services\Reports\Exports\ReportDataset;
use App\Services\Reports\ReportScope;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Cancellation requests (`cancellations.decide` or `refunds.process`). Reason code only; no free text or internal note (D323).
 */
class CancellationsDataset extends ReportDataset
{
    public function key(): string
    {
        return 'cancellations';
    }

    public function permissions(): array
    {
        return ['cancellations.decide', 'refunds.process'];
    }

    public function tables(): array
    {
        return ['cancellation_requests'];
    }

    public function columns(): array
    {
        return ['reference', 'booking', 'requester', 'requester_role', 'reason', 'status', 'created_at', 'decided_at'];
    }

    public function query(ReportScope $scope, array $filters): Builder
    {
        $query = DB::table('cancellation_requests')
            ->leftJoin('bookings', 'bookings.id', '=', 'cancellation_requests.booking_id')
            ->leftJoin('users as requester', 'requester.id', '=', 'cancellation_requests.requester_id')
            ->select(['cancellation_requests.id', 'cancellation_requests.reference', 'bookings.reference as booking_reference', 'requester.name as requester_name',
                'cancellation_requests.requester_role', 'cancellation_requests.reason_code', 'cancellation_requests.status', 'cancellation_requests.created_at', 'cancellation_requests.decided_at'])
            ->when($filters['status'] ?? null, fn (Builder $q, string $status) => $q->where('cancellation_requests.status', $status))
            ->orderByDesc('cancellation_requests.created_at')->orderByDesc('cancellation_requests.id');

        return $scope->applyPeriod($query, 'cancellation_requests.created_at');
    }

    public function map(object $row): array
    {
        return [
            $row->reference, $row->booking_reference, $row->requester_name, __('enums.cancellation_requester_role.'.$row->requester_role),
            __('enums.cancellation_reason.'.$row->reason_code), Cell::status($row->status), Cell::dateTime($row->created_at), Cell::dateTime($row->decided_at),
        ];
    }
}
