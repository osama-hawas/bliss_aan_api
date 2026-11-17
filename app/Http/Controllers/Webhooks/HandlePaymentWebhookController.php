<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Services\Payments\PaymentWebhooks;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

/**
 * `POST /webhooks/payments/{gateway}` (D189, slice-10-contract.md §8). No auth: the signature is the authentication.
 * 200 once the event is stored (also for duplicates and unmatched events), 401 INVALID_SIGNATURE, 404 unknown gateway.
 */
class HandlePaymentWebhookController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly PaymentWebhooks $webhooks) {}

    public function __invoke(Request $request, string $gateway)
    {
        $result = $this->webhooks->handle($gateway, $request);

        return $this->success(['result' => $result->value]);
    }
}
