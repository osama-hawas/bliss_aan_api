<?php

namespace App\Http\Controllers\Api\V1\Admin\Reviews;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Reviews\ListReviewsRequest;
use App\Http\Resources\Api\V1\Admin\Reviews\AdminReviewResource;
use App\Services\Reviews\ReviewModerationQueries;
use App\Support\Pagination;
use App\Traits\ApiResponse;

/**
 * Reviews for moderation (`reviews.moderate`, §8).
 */
class ListReviewsController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ReviewModerationQueries $queries) {}

    public function __invoke(ListReviewsRequest $request)
    {
        return $this->success(AdminReviewResource::collection(
            $this->queries->reviews($request->filters(), $request->validated('sort'), Pagination::perPage($request))));
    }
}
