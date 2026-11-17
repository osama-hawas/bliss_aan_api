<?php

namespace App\Http\Controllers\Api\V1\Admin\Reviews;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Reviews\ListContentReportsRequest;
use App\Http\Resources\Api\V1\Admin\Reviews\AdminContentReportResource;
use App\Services\Reviews\ReviewModerationQueries;
use App\Support\Pagination;
use App\Traits\ApiResponse;

/**
 * Content reports, open first (`reviews.moderate`, D262).
 */
class ListContentReportsController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ReviewModerationQueries $queries) {}

    public function __invoke(ListContentReportsRequest $request)
    {
        return $this->success(AdminContentReportResource::collection($this->queries->reports([
            'status' => $request->validated('filter.status'),
            'reason' => $request->validated('filter.reason'),
            'target_type' => $request->validated('filter.target_type'),
        ], Pagination::perPage($request))));
    }
}
