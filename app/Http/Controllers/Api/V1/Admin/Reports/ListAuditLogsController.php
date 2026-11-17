<?php

namespace App\Http\Controllers\Api\V1\Admin\Reports;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Reports\ListAuditLogsRequest;
use App\Http\Resources\Api\V1\Admin\Reports\AuditLogResource;
use App\Services\Audit\AuditLogQueries;
use App\Support\Pagination;
use App\Traits\ApiResponse;

/**
 * `GET admin/audit-logs` (`audit.view`): read-only action log with search and filters (D321).
 */
class ListAuditLogsController extends Controller
{
    use ApiResponse;

    public function __invoke(ListAuditLogsRequest $request, AuditLogQueries $logs)
    {
        $page = $logs->list($request->filters(), $request->validated('sort'), Pagination::perPage($request));

        return $this->success(AuditLogResource::collection($page));
    }
}
