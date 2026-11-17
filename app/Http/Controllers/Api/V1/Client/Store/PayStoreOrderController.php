<?php

namespace App\Http\Controllers\Api\V1\Client\Store;

use App\Enums\PayableType;
use App\Enums\PaymentMethodType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Client\Store\PayStoreOrderRequest;
use App\Http\Resources\Api\V1\Client\Payments\ClientPaymentResource;
use App\Http\Resources\Api\V1\Client\Store\ClientStoreOrderResource;
use App\Services\Payments\PaymentInstrument;
use App\Services\Payments\PaymentIntents;
use App\Services\Store\StoreOrderQueries;
use App\Traits\ApiResponse;

/**
 * Pays her order through the Slice 10 core (§6.3, D214): `PaymentIntents::create` for payable `store_order`. Returns the Slice 10 payment resource and `meta.order`.
 */
class PayStoreOrderController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly PaymentIntents $intents, private readonly StoreOrderQueries $queries) {}

    public function __invoke(PayStoreOrderRequest $request, int $order)
    {
        $client = $request->user();
        $order = $this->queries->clientOrder($client, $order);

        $savedId = $request->validated('payment_method_id');
        $instrument = new PaymentInstrument(
            type: PaymentMethodType::from($request->validated('method')),
            savedMethodId: $savedId === null ? null : (int) $savedId,
            token: $request->validated('token'),
            save: (bool) $request->validated('save_method', false),
        );

        [$payment, $isNew] = $this->intents->create($client, PayableType::StoreOrder, $order, $instrument, $request->validated('idempotency_key'));

        $fresh = $this->queries->clientOrder($client, $order->id);

        return $this->success(new ClientPaymentResource($payment), null, $isNew ? 201 : 200, [
            'order' => (new ClientStoreOrderResource($fresh))->resolve($request),
        ]);
    }
}
