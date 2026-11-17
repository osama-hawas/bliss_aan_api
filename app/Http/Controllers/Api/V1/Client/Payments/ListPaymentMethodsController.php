<?php

namespace App\Http\Controllers\Api\V1\Client\Payments;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Client\Payments\SavedPaymentMethodResource;
use App\Services\Payments\SavedPaymentMethods;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

/**
 * Her saved cards, default first (D188).
 */
class ListPaymentMethodsController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly SavedPaymentMethods $methods) {}

    public function __invoke(Request $request)
    {
        return $this->success(SavedPaymentMethodResource::collection($this->methods->list($request->user())));
    }
}
