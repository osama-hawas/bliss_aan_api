<?php

namespace App\Http\Controllers\Api\V1\Provider\Reviews;

use App\Enums\ReportReason;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Provider\Reviews\ReportReviewRequest;
use App\Services\Availability\AvailabilityBlocks;
use App\Services\Reviews\ReviewQueries;
use App\Services\Reviews\ReviewService;
use App\Traits\ApiResponse;

/**
 * The provider reports a review on her profile (D129). Stored for Slice 17; the client is never told.
 */
class ReportReviewController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly AvailabilityBlocks $blocks,
        private readonly ReviewQueries $reviews,
        private readonly ReviewService $service,
    ) {}

    public function __invoke(ReportReviewRequest $request, int $review)
    {
        $provider = $this->blocks->providerOf($request->user());
        $this->service->report($request->user(), $this->reviews->providerReview($provider, $review),
            ReportReason::from($request->validated('reason_code')), $request->validated('details'));

        return $this->success(['reported' => true], __('Report sent.'), 201);
    }
}
