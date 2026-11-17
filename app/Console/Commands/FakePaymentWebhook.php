<?php

namespace App\Console\Commands;

use App\Integrations\Payments\FakePaymentGateway;
use App\Integrations\Payments\PaymentGateway;
use App\Integrations\Payments\WebhookEvent;
use App\Models\Payment;
use App\Services\Payments\PaymentWebhooks;
use Illuminate\Console\Command;
use Illuminate\Http\Request;

/**
 * Slice 10 test aid (fake driver only): answers the bank step of a `requires_action` payment and delivers the signed
 * webhook, exactly like the fake checkout page.
 */
class FakePaymentWebhook extends Command
{
    protected $signature = 'payments:fake-webhook {reference : PY-… payment reference} {outcome : succeeded|failed}';

    protected $description = 'Fake gateway: settle a payment and deliver its signed webhook';

    public function handle(PaymentGateway $gateway, PaymentWebhooks $webhooks): int
    {
        if (! $gateway instanceof FakePaymentGateway) {
            $this->error('Only available with PAYMENT_DRIVER=fake.');

            return self::FAILURE;
        }
        $outcome = $this->argument('outcome');
        if (! in_array($outcome, ['succeeded', 'failed'], true)) {
            $this->error('Outcome must be succeeded or failed.');

            return self::INVALID;
        }
        $payment = Payment::query()->where('reference', $this->argument('reference'))->first();
        if (! $payment?->gateway_reference) {
            $this->error('No payment with a gateway reference for that reference.');

            return self::FAILURE;
        }

        $approve = $outcome === 'succeeded';
        $gateway->settle($payment->gateway_reference, $approve);
        [$body, $headers] = FakePaymentGateway::webhook($approve ? WebhookEvent::PAYMENT_SUCCEEDED : WebhookEvent::PAYMENT_FAILED,
            $payment->gateway_reference, $approve ? null : 'authentication_failed', null, null, $payment->reference);
        $request = Request::create('/webhooks/payments/fake', 'POST', [], [], [], [], $body);
        $request->headers->add($headers);

        $result = $webhooks->handle('fake', $request);
        $this->info("Webhook {$result->value}: {$payment->reference} → {$outcome}.");

        return self::SUCCESS;
    }
}
