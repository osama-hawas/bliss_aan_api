<?php

namespace App\Http\Controllers\Api\V1\Provider\Reviews;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Provider\Reviews\ReplyToReviewRequest;
use App\Http\Resources\Api\V1\Provider\Reviews\ProviderReviewResource;
use App\Services\Providers\ProviderSetup;
use App\Services\Reviews\ReviewQueries;
use App\Services\Reviews\ReviewService;
use App\Traits\ApiResponse;

/**
 * One public reply per review (D129).
 */
class ReplyToReviewController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly ProviderSetup $setup,
        private readonly ReviewQueries $reviews,
        private readonly ReviewService $service,
    ) {}

    public function __invoke(ReplyToReviewRequest $request, int $review)
    {
        $provider = $this->setup->activeProvider($request->user());
        $model = $this->service->reply($provider, $this->reviews->providerReview($provider, $review), $request->validated('reply'));

        return $this->success(new ProviderReviewResource($model->load(['client', 'booking'])), __('Reply published.'));
    }
}
