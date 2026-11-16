<?php

namespace App\Http\Controllers\Api\V1\Shared\Reviews;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Shared\Reviews\PublicReviewResource;
use App\Services\Reviews\ReviewQueries;
use App\Support\Pagination;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

/**
 * Published reviews of a visible provider (D75, D129). Public.
 */
class ListProviderReviewsController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ReviewQueries $reviews) {}

    public function __invoke(Request $request, int $provider)
    {
        return $this->success(PublicReviewResource::collection($this->reviews->publicForProvider($provider, Pagination::perPage($request))));
    }
}
