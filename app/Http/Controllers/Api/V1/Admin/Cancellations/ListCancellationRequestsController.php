<?php

namespace App\Http\Controllers\Api\V1\Admin\Cancellations;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Cancellations\ListCancellationRequestsRequest;
use App\Http\Resources\Api\V1\Admin\Cancellations\AdminCancellationResource;
use App\Services\Cancellations\CancellationQueries;
use App\Support\Pagination;
use App\Traits\ApiResponse;

/**
 * Cancellation requests queue (bookings.view).
 */
class ListCancellationRequestsController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly CancellationQueries $queries) {}

    public function __invoke(ListCancellationRequestsRequest $request)
    {
        $page = $this->queries->admin($request->validated('filter.status'), $request->validated('filter.requester_role'), $request->validated('sort'), Pagination::perPage($request),
            $request->filled('q') ? trim($request->validated('q')) : null);

        return $this->success(AdminCancellationResource::collection($page));
    }
}
