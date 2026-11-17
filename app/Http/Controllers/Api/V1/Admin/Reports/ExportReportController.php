<?php

namespace App\Http\Controllers\Api\V1\Admin\Reports;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Reports\ExportReportRequest;
use App\Services\Reports\Exports\ReportExporter;
use App\Traits\ApiResponse;

/**
 * `GET admin/exports/{dataset}` (`reports.export` + the dataset's permission): `.xlsx` download (D322, D323).
 */
class ExportReportController extends Controller
{
    use ApiResponse;

    public function __invoke(ExportReportRequest $request, ReportExporter $exporter, string $dataset)
    {
        $staff = $request->user();
        $found = $exporter->dataset($staff, $dataset);

        return $exporter->download($staff, $found, $request->scope(), $request->filters($found->extraFilters()));
    }
}
