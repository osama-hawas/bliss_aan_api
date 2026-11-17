<?php

namespace App\Http\Controllers\Api\V1\Client\Payments;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Client\Payments\StorePaymentMethodRequest;
use App\Http\Resources\Api\V1\Client\Payments\SavedPaymentMethodResource;
use App\Services\Payments\SavedPaymentMethods;
use App\Traits\ApiResponse;

/**
 * Saves a card by its gateway token, without paying (D193). 201 new, 200 when that token is already saved.
 */
class StorePaymentMethodController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly SavedPaymentMethods $methods) {}

    public function __invoke(StorePaymentMethodRequest $request)
    {
        [$method, $isNew] = $this->methods->add($request->user(), $request->validated('token'));

        return $this->success(new SavedPaymentMethodResource($method), __('payments.method_saved'), $isNew ? 201 : 200);
    }
}
