<?php

namespace App\Http\Controllers\Api\V1\Client\Store;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Client\Store\CheckoutPreviewRequest;
use App\Http\Resources\Api\V1\Client\Store\CheckoutPreviewResource;
use App\Services\Store\CheckoutService;
use App\Traits\ApiResponse;

/**
 * Checkout preview (slice-9b-contract.md §6.1). Writes nothing.
 */
class PreviewCheckoutController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly CheckoutService $checkout) {}

    public function __invoke(CheckoutPreviewRequest $request)
    {
        $addressId = $request->validated('address_id');
        $quote = $this->checkout->preview($request->user(), $addressId === null ? null : (int) $addressId, $request->validated('promo_code'));

        return $this->success(new CheckoutPreviewResource($quote));
    }
}
