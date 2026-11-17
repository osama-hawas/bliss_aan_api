<?php

namespace App\Http\Controllers\Api\V1\Admin\Payments;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Payments\ListPaymentsRequest;
use App\Http\Resources\Api\V1\Admin\Payments\AdminPaymentResource;
use App\Services\Payments\PaymentQueries;
use App\Support\Pagination;
use App\Traits\ApiResponse;

/**
 * Payments list (finance.view, D192).
 */
class ListPaymentsController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly PaymentQueries $queries) {}

    public function __invoke(ListPaymentsRequest $request)
    {
        $page = $this->queries->admin([
            'q' => $request->validated('q'),
            'status' => $request->validated('filter.status'),
            'payable_type' => $request->validated('filter.payable_type'),
            'refund' => $request->validated('filter.refund'),
        ], $request->validated('sort'), Pagination::perPage($request));

        return $this->success(AdminPaymentResource::collection($page));
    }
}
