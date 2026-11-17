<?php

namespace App\Services\Reports\Exports\Datasets;

use App\Services\Reports\Exports\Cell;
use App\Services\Reports\Exports\ReportDataset;
use App\Services\Reports\ReportScope;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Payout requests (`finance.view` or `payouts.process`), S11's `payout_requests`. Never the IBAN (D323).
 */
class PayoutsDataset extends ReportDataset
{
    public function key(): string
    {
        return 'payouts';
    }

    public function permissions(): array
    {
        return ['finance.view', 'payouts.process'];
    }

    public function tables(): array
    {
        return ['payout_requests'];
    }

    public function columns(): array
    {
        return ['reference', 'provider', 'amount', 'status', 'transfer_reference', 'processed_at', 'created_at'];
    }

    public function query(ReportScope $scope, array $filters): Builder
    {
        $query = DB::table('payout_requests')
            ->leftJoin('providers', 'providers.id', '=', 'payout_requests.provider_id')
            ->select(['payout_requests.id', 'payout_requests.reference', 'providers.display_name as provider_name', 'payout_requests.amount_cents', 'payout_requests.status',
                'payout_requests.transfer_reference', 'payout_requests.processed_at', 'payout_requests.created_at'])
            ->when($filters['status'] ?? null, fn (Builder $q, string $status) => $q->where('payout_requests.status', $status))
            ->orderByDesc('payout_requests.created_at')->orderByDesc('payout_requests.id');

        return $scope->applyPeriod($query, 'payout_requests.created_at');
    }

    public function map(object $row): array
    {
        return [
            $row->reference, $row->provider_name, Cell::cents($row->amount_cents), Cell::status($row->status), $row->transfer_reference,
            Cell::dateTime($row->processed_at), Cell::dateTime($row->created_at),
        ];
    }
}
