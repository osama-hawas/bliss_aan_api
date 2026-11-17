<?php

namespace App\Http\Controllers\Api\V1\Admin\Finance;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Admin\Finance\AdminLedgerAdjustmentResource;
use App\Services\Finance\AdjustmentQueries;
use App\Traits\ApiResponse;

/**
 * One adjustment with its history (finance.view or finance.process).
 */
class ShowLedgerAdjustmentController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly AdjustmentQueries $queries) {}

    public function __invoke(int $adjustment)
    {
        return $this->success($this->detail($this->queries, $adjustment));
    }

    public static function detail(AdjustmentQueries $queries, int $id): AdminLedgerAdjustmentResource
    {
        $model = $queries->one($id);
        $resource = new AdminLedgerAdjustmentResource($model);
        $resource->history = $queries->history($model);

        return $resource;
    }
}
