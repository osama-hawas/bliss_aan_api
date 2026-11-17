<?php

namespace App\Http\Controllers\Api\V1\Client\Payments;

use App\Enums\PayableType;
use App\Enums\PaymentMethodType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Client\Payments\PayOfferRequest;
use App\Http\Resources\Api\V1\Client\Payments\ClientPaymentResource;
use App\Services\Payments\PayableRegistry;
use App\Services\Payments\PaymentInstrument;
use App\Services\Payments\PaymentIntents;
use App\Services\Payments\PaymentQueries;
use App\Traits\ApiResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Pays an offer on her own request (D185, slice-10-contract.md §3): on success the booking is confirmed by the same
 * acceptance rules; if acceptance fails the payment is refunded in full. A declined card is a `failed` payment, not an
 * error. 201 new, 200 replay.
 */
class PayOfferController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly PayableRegistry $registry,
        private readonly PaymentIntents $intents,
        private readonly PaymentQueries $queries,
    ) {}

    public function __invoke(PayOfferRequest $request, int $offer)
    {
        $client = $request->user();
        $model = $this->registry->for(PayableType::Offer)->findForPayer($client, $offer);
        Gate::authorize('accept', $model->serviceRequest);

        $instrument = new PaymentInstrument(
            PaymentMethodType::from($request->validated('method')),
            $request->validated('payment_method_id') !== null ? (int) $request->validated('payment_method_id') : null,
            $request->validated('token'),
            (bool) $request->validated('save_method', false),
        );
        [$payment, $isNew] = $this->intents->create($client, PayableType::Offer, $model, $instrument, $request->validated('idempotency_key'));

        return $this->success(new ClientPaymentResource($this->queries->clientPayment($client, $payment->id)), __('payments.created'), $isNew ? 201 : 200);
    }
}
