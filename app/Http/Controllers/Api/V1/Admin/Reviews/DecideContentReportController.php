<?php

namespace App\Http\Controllers\Api\V1\Admin\Reviews;

use App\Enums\ContentReportDecision;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Reviews\DecideContentReportRequest;
use App\Http\Resources\Api\V1\Admin\Reviews\AdminContentReportResource;
use App\Services\Reviews\ReviewModeration;
use App\Services\Reviews\ReviewModerationQueries;
use App\Traits\ApiResponse;

/**
 * Decide a content report (`reviews.moderate`, D262).
 */
class DecideContentReportController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ReviewModerationQueries $queries, private readonly ReviewModeration $moderation) {}

    public function __invoke(DecideContentReportRequest $request, int $report)
    {
        $this->moderation->decide($request->user(), $this->queries->report($report), ContentReportDecision::from($request->validated('decision')),
            $request->validated('reason'), $request->validated('internal_note'));

        return $this->success(new AdminContentReportResource($this->queries->report($report)), __('Decision saved.'));
    }
}
