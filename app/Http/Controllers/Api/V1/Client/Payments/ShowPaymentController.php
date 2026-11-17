<?php

namespace App\Http\Controllers\Api\V1\Client\Payments;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Client\Payments\ClientPaymentResource;
use App\Services\Payments\PaymentIntents;
use App\Services\Payments\PaymentQueries;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

/**
 * Her own payment; the app polls it while it is open. The server asks the gateway itself (D189): the app's redirect
 * never decides the state.
 */
class ShowPaymentController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly PaymentQueries $queries,
        private readonly PaymentIntents $intents,
    ) {}

    public function __invoke(Request $request, int $payment)
    {
        $model = $this->queries->clientPayment($request->user(), $payment);
        $this->intents->refresh($model);

        return $this->success(new ClientPaymentResource($this->queries->clientPayment($request->user(), $payment)));
    }
}
