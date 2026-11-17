<?php

namespace App\Http\Controllers\Api\V1\Admin\Payouts;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Payouts\ListPayoutsRequest;
use App\Http\Resources\Api\V1\Admin\Payouts\AdminPayoutResource;
use App\Services\Payouts\PayoutQueries;
use App\Support\Pagination;
use App\Traits\ApiResponse;

/**
 * Payouts queue (finance.view or payouts.process).
 */
class ListPayoutsController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly PayoutQueries $queries) {}

    public function __invoke(ListPayoutsRequest $request)
    {
        $page = $this->queries->admin($request->validated('q'), $request->validated('filter') ?? [], $request->validated('sort'), Pagination::perPage($request));

        return $this->success(AdminPayoutResource::collection($page));
    }
}
