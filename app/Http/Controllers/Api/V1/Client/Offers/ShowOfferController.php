<?php

namespace App\Http\Controllers\Api\V1\Client\Offers;

use App\Enums\PayableType;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Client\Offers\ClientOfferResource;
use App\Http\Resources\Api\V1\Client\Payments\ClientPaymentResource;
use App\Services\Payments\PaymentQueries;
use App\Services\Payments\PaymentSettings;
use App\Services\ServiceRequests\ServiceRequestQueries;
use App\Support\Money;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * One offer on her own request; any other id (or a superseded/withdrawn offer) is a 404. Slice 10 adds `payment`.
 */
class ShowOfferController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly ServiceRequestQueries $queries,
        private readonly PaymentSettings $paymentSettings,
        private readonly PaymentQueries $payments,
    ) {}

    public function __invoke(Request $request, int $offer)
    {
        $model = $this->queries->clientOffer($request->user(), $offer);
        Gate::authorize('viewAsClient', $model->serviceRequest);

        // Slice 10 (D185): whether she must pay before the booking is confirmed, and her latest attempt.
        $latest = $this->payments->latestFor($request->user(), PayableType::Offer, $model->id);

        return $this->success([
            ...(new ClientOfferResource($model))->resolve($request),
            'payment' => [
                'required' => $this->paymentSettings->bookingPaymentRequired(),
                'amount' => Money::format($model->total),
                'latest' => $latest ? (new ClientPaymentResource($latest))->resolve($request) : null,
            ],
        ]);
    }
}
