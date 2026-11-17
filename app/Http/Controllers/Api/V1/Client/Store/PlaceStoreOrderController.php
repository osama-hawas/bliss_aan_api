<?php

namespace App\Http\Controllers\Api\V1\Client\Store;

use App\Helpers\ApiResponder;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Client\Store\PlaceStoreOrderRequest;
use App\Http\Resources\Api\V1\Client\Store\CheckoutPreviewResource;
use App\Http\Resources\Api\V1\Client\Store\ClientStoreOrderResource;
use App\Services\Store\CheckoutService;
use App\Services\Store\PriceChanged;
use App\Services\Store\StoreOrderQueries;
use App\Traits\ApiResponse;

/**
 * Checkout (§6.2): creates her unpaid order snapshot; 201 new / 200 replay; 409 PRICE_CHANGED carries the new preview in `data`.
 */
class PlaceStoreOrderController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly CheckoutService $checkout, private readonly StoreOrderQueries $queries) {}

    public function __invoke(PlaceStoreOrderRequest $request)
    {
        try {
            [$order, $isNew] = $this->checkout->create($request->user(), $request->validated('idempotency_key'), $request->safe()->only(['address_id', 'promo_code', 'expected_total']));
        } catch (PriceChanged $e) {
            $response = ApiResponder::error($e->errorCode, $e->getMessage());
            $body = $response->getData(true);
            $body['data'] = (new CheckoutPreviewResource($e->quote))->resolve($request);

            return $response->setData($body);
        }

        return $this->success(new ClientStoreOrderResource($this->queries->clientOrder($request->user(), $order->id)), null, $isNew ? 201 : 200);
    }
}
