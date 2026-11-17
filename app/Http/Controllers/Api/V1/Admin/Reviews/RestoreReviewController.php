<?php

namespace App\Http\Controllers\Api\V1\Admin\Reviews;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Reviews\RestoreReviewRequest;
use App\Http\Resources\Api\V1\Admin\Reviews\AdminReviewResource;
use App\Services\Reviews\ReviewModeration;
use App\Services\Reviews\ReviewModerationQueries;
use App\Traits\ApiResponse;

/**
 * Restore a hidden review (`reviews.moderate`, D261).
 */
class RestoreReviewController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ReviewModerationQueries $queries, private readonly ReviewModeration $moderation) {}

    public function __invoke(RestoreReviewRequest $request, int $review)
    {
        $this->moderation->restore($request->user(), $this->queries->review($review), $request->validated('reason'));

        return $this->success(new AdminReviewResource($this->queries->review($review)), __('Review restored.'));
    }
}
