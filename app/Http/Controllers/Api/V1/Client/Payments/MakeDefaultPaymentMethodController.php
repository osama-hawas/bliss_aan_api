<?php

namespace App\Http\Controllers\Api\V1\Client\Payments;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Client\Payments\SavedPaymentMethodResource;
use App\Services\Payments\SavedPaymentMethods;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

/**
 * Makes one of her cards the default (D193).
 */
class MakeDefaultPaymentMethodController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly SavedPaymentMethods $methods) {}

    public function __invoke(Request $request, int $method)
    {
        return $this->success(new SavedPaymentMethodResource($this->methods->makeDefault($request->user(), $method)), __('payments.method_defaulted'));
    }
}
