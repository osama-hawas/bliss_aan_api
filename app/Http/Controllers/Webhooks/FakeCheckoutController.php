<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Integrations\Payments\FakePaymentGateway;
use App\Integrations\Payments\PaymentGateway;
use App\Integrations\Payments\WebhookEvent;
use App\Models\Payment;
use App\Services\Payments\PaymentWebhooks;
use Illuminate\Http\Request;

/**
 * Fake driver only, local/testing only (slice-10-contract.md §11): stands in for the bank's 3-D Secure page. Approve /
 * Decline settle the fake charge and deliver a correctly signed webhook in-process (no HTTP call to ourselves).
 */
class FakeCheckoutController extends Controller
{
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly PaymentWebhooks $webhooks,
    ) {}

    public function show(string $reference)
    {
        $payment = $this->payment($reference);

        return response()->view('payments.fake-checkout', ['payment' => $payment, 'reference' => $reference, 'done' => false]);
    }

    public function submit(Request $request, string $reference)
    {
        $payment = $this->payment($reference);
        $approve = $request->input('decision') === 'approve';

        /** @var FakePaymentGateway $gateway */
        $gateway = $this->gateway;
        $gateway->settle($reference, $approve);
        [$body, $headers] = FakePaymentGateway::webhook($approve ? WebhookEvent::PAYMENT_SUCCEEDED : WebhookEvent::PAYMENT_FAILED,
            $reference, $approve ? null : 'authentication_failed', null, null, $payment->reference);
        $webhook = Request::create('/webhooks/payments/fake', 'POST', [], [], [], [], $body);
        $webhook->headers->add($headers);
        $this->webhooks->handle('fake', $webhook);

        return response()->view('payments.fake-checkout', ['payment' => $payment->fresh(), 'reference' => $reference, 'done' => true]);
    }

    private function payment(string $reference): Payment
    {
        abort_unless($this->gateway instanceof FakePaymentGateway && app()->environment(['local', 'testing']), 404);

        return Payment::query()->where('gateway_reference', $reference)->firstOrFail();
    }
}
