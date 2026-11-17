<?php

namespace App\Http\Controllers\Api\V1\Admin\Reports;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Admin\Reports\AuditLogDetailResource;
use App\Services\Audit\AuditLogQueries;
use App\Traits\ApiResponse;

/**
 * `GET admin/audit-logs/{id}` (`audit.view`): one entry with old/new values and the changed fields.
 */
class ShowAuditLogController extends Controller
{
    use ApiResponse;

    public function __invoke(AuditLogQueries $logs, int $auditLog)
    {
        return $this->success(new AuditLogDetailResource($logs->find($auditLog)));
    }
}
