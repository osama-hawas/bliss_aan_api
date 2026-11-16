<?php

namespace App\Http\Controllers\Api\V1\Provider\Reviews;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Provider\Reviews\ProviderReviewResource;
use App\Services\Availability\AvailabilityBlocks;
use App\Services\Reviews\ReviewQueries;
use App\Support\Pagination;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

/**
 * Her reviews, newest first, with the rating summary in meta.summary.
 */
class ListReviewsController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly AvailabilityBlocks $blocks,
        private readonly ReviewQueries $reviews,
    ) {}

    public function __invoke(Request $request)
    {
        $provider = $this->blocks->providerOf($request->user());
        $page = $this->reviews->forProvider($provider, Pagination::perPage($request));

        return $this->success(ProviderReviewResource::collection($page), null, 200, ['summary' => $this->reviews->summary($provider)]);
    }
}
