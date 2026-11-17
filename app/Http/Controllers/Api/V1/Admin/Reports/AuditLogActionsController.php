<?php

namespace App\Http\Controllers\Api\V1\Admin\Reports;

use App\Http\Controllers\Controller;
use App\Services\Audit\AuditLogQueries;
use App\Traits\ApiResponse;

/**
 * `GET admin/audit-logs/actions` (`audit.view`): actions present in the log, with labels, for the filter.
 */
class AuditLogActionsController extends Controller
{
    use ApiResponse;

    public function __invoke(AuditLogQueries $logs)
    {
        return $this->success($logs->actions());
    }
}
