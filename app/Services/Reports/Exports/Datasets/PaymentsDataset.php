<?php

namespace App\Services\Reports\Exports\Datasets;

use App\Services\Reports\Exports\Cell;
use App\Services\Reports\Exports\ReportDataset;
use App\Services\Reports\ReportScope;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Payments (`finance.view`), S10's `payments` table. Card = brand + last 4 only, never a token (D323).
 */
class PaymentsDataset extends ReportDataset
{
    public function key(): string
    {
        return 'payments';
    }

    public function permissions(): array
    {
        return ['finance.view'];
    }

    public function tables(): array
    {
        return ['payments'];
    }

    public function columns(): array
    {
        return ['reference', 'client', 'payable', 'amount', 'currency', 'method', 'card', 'status', 'refunded_amount', 'paid_at', 'created_at'];
    }

    public function query(ReportScope $scope, array $filters): Builder
    {
        $query = DB::table('payments')
            ->leftJoin('users as payer', 'payer.id', '=', 'payments.user_id')
            ->select(['payments.id', 'payments.reference', 'payer.name as payer_name', 'payments.payable_type', 'payments.amount', 'payments.currency', 'payments.method',
                'payments.card', 'payments.status', 'payments.refunded_amount', 'payments.paid_at', 'payments.created_at'])
            ->when($filters['status'] ?? null, fn (Builder $q, string $status) => $q->where('payments.status', $status))
            ->orderByDesc('payments.created_at')->orderByDesc('payments.id');

        return $scope->applyPeriod($query, 'payments.created_at');
    }

    public function map(object $row): array
    {
        return [
            $row->reference, $row->payer_name, __('reports.columns.'.($row->payable_type === 'store_order' ? 'store_order' : 'booking')), Cell::money($row->amount), $row->currency,
            $row->method, self::card($row->card), Cell::status($row->status), Cell::money($row->refunded_amount), Cell::dateTime($row->paid_at), Cell::dateTime($row->created_at),
        ];
    }

    /** `visa •••• 4242` from the card snapshot JSON (brand + last 4 only). */
    private static function card(?string $json): ?string
    {
        $card = $json ? json_decode($json, true) : null;

        return is_array($card) && isset($card['last4']) ? trim(($card['brand'] ?? '').' •••• '.$card['last4']) : null;
    }
}
