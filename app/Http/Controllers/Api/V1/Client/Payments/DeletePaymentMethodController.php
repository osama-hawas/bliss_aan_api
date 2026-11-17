<?php

namespace App\Http\Controllers\Api\V1\Client\Payments;

use App\Http\Controllers\Controller;
use App\Services\Payments\SavedPaymentMethods;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

/**
 * Removes one of her cards (soft delete; past payments keep their card snapshot).
 */
class DeletePaymentMethodController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly SavedPaymentMethods $methods) {}

    public function __invoke(Request $request, int $method)
    {
        $this->methods->delete($request->user(), $method);

        return $this->success(null, __('payments.method_removed'));
    }
}
