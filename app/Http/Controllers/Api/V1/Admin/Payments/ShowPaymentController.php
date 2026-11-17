<?php

namespace App\Http\Controllers\Api\V1\Admin\Payments;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Admin\Payments\AdminPaymentResource;
use App\Services\Payments\PaymentQueries;
use App\Traits\ApiResponse;

/**
 * One payment with its refunds and history (finance.view, D192).
 */
class ShowPaymentController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly PaymentQueries $queries) {}

    public function __invoke(int $payment)
    {
        $model = $this->queries->adminPayment($payment);

        return $this->success((new AdminPaymentResource($model))->withDetail($this->queries->history($model)));
    }
}
