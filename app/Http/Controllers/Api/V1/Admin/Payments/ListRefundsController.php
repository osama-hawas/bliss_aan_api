<?php

namespace App\Http\Controllers\Api\V1\Admin\Payments;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Payments\ListRefundsRequest;
use App\Http\Resources\Api\V1\Admin\Payments\AdminRefundResource;
use App\Services\Payments\PaymentQueries;
use App\Support\Pagination;
use App\Traits\ApiResponse;

/**
 * Refunds list (finance.view or refunds.process, D192).
 */
class ListRefundsController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly PaymentQueries $queries) {}

    public function __invoke(ListRefundsRequest $request)
    {
        $page = $this->queries->adminRefunds([
            'q' => $request->validated('q'),
            'status' => $request->validated('filter.status'),
            'kind' => $request->validated('filter.kind'),
        ], $request->validated('sort'), Pagination::perPage($request));

        return $this->success(AdminRefundResource::collection($page));
    }
}
