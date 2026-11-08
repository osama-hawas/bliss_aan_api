<?php

namespace App\Http\Controllers\Api\V1\Admin\Providers;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Providers\ListApplicationsRequest;
use App\Http\Resources\Api\V1\Admin\Providers\ApplicationResource;
use App\Services\Providers\ProviderReview;
use App\Support\Pagination;
use App\Traits\ApiResponse;

/**
 * The review queue (`providers.review`): search by name or phone, filter by status, account type, city.
 */
class ListApplicationsController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ProviderReview $review) {}

    public function __invoke(ListApplicationsRequest $request)
    {
        $page = $this->review->queue($request->filters(), $request->validated('sort'), Pagination::perPage($request));

        return $this->success(ApplicationResource::collection($page));
    }
}
