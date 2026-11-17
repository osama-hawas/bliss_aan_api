<?php

namespace App\Http\Controllers\Api\V1\Admin\Finance;

use App\Http\Controllers\Controller;
use App\Http\Resources\Concerns\FormatsCents;
use App\Services\Finance\Reconciliation;
use App\Traits\ApiResponse;

/**
 * Every reconciliation check with its count and amount (finance.view, D296).
 */
class ReconciliationChecksController extends Controller
{
    use ApiResponse, FormatsCents;

    public function __invoke(Reconciliation $reconciliation)
    {
        return $this->success(array_map(fn (array $row) => [
            'key' => $row['check']->value,
            'label' => $row['check']->label(),
            'description' => $row['check']->description(),
            'count' => $row['count'],
            'amount' => $this->cents($row['amount_cents']),
        ], $reconciliation->summary()));
    }
}
