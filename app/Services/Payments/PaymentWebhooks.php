<?php

namespace App\Services\Payments;

use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Integrations\Payments\InvalidWebhookSignature;
use App\Integrations\Payments\PaymentGateway;
use App\Integrations\Payments\WebhookEvent;
use App\Models\Payment;
use App\Models\PaymentWebhookEvent;
use App\Models\Refund;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Gateway webhooks (D189, slice-10-contract.md §8). The signature is verified first (nothing stored otherwise); the
 * event is stored once per (gateway, event_id); a redelivery of a processed event is a no-op. Matched events go through
 * the state machines (forward-only, so out-of-order events are harmless). A processing error leaves the event
 * unprocessed and answers 500, so the gateway delivers it again.
 */
class PaymentWebhooks
{
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly PaymentStateMachine $payments,
        private readonly RefundStateMachine $refunds,
        private readonly PaymentIntents $intents,
    ) {}

    public function handle(string $gateway, Request $request): WebhookResult
    {
        if ($gateway !== $this->gateway->name()) {
            throw new ApiException(ErrorCode::NotFound);
        }
        try {
            $event = $this->gateway->parseWebhook($request);
        } catch (InvalidWebhookSignature $e) {
            Log::warning('Payment webhook refused.', ['gateway' => $gateway, 'reason' => $e->getMessage()]);

            throw new ApiException(ErrorCode::InvalidSignature);
        }

        return $this->process($gateway, $event);
    }

    public function process(string $gateway, WebhookEvent $event): WebhookResult
    {
        $inserted = PaymentWebhookEvent::query()->insertOrIgnore([
            'gateway' => $gateway,
            'event_id' => $event->id,
            'type' => $event->type,
            'gateway_reference' => $event->gatewayReference,
            'payload' => json_encode($event->raw, JSON_THROW_ON_ERROR),
            'received_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $row = PaymentWebhookEvent::query()->where('gateway', $gateway)->where('event_id', $event->id)->firstOrFail();
        if (! $inserted && $row->processed_at !== null) {
            return WebhookResult::Duplicate;
        }

        $result = match (true) {
            $event->isPaymentEvent() => $this->payment($event),
            $event->isRefundEvent() => $this->refund($event),
            default => WebhookResult::Ignored,
        };
        $row->update(['processed_at' => now(), 'result' => $result->value]);
        if ($result === WebhookResult::Unmatched) {
            Log::warning('Payment webhook matched nothing.', ['gateway' => $gateway, 'event_id' => $event->id, 'type' => $event->type]);
        }

        return $result;
    }

    private function payment(WebhookEvent $event): WebhookResult
    {
        $payment = $event->gatewayReference ? Payment::query()->where('gateway_reference', $event->gatewayReference)->first() : null;
        // Review H1: a charge whose call timed out has no gateway reference here yet; match it on our own reference,
        // unless that payment is already tied to another charge.
        if (! $payment && $event->merchantReference) {
            $payment = Payment::query()->where('reference', $event->merchantReference)
                ->where(fn ($q) => $q->whereNull('gateway_reference')->orWhere('gateway_reference', $event->gatewayReference))->first();
        }
        if (! $payment) {
            return WebhookResult::Unmatched;
        }
        $this->intents->settle($this->payments->apply($payment, GatewayOutcome::fromResult($event->toResult()), 'webhook'));

        return WebhookResult::Applied;
    }

    private function refund(WebhookEvent $event): WebhookResult
    {
        $refund = $event->gatewayReference ? Refund::query()->where('gateway_reference', $event->gatewayReference)->first() : null;
        if (! $refund) {
            return WebhookResult::Unmatched;
        }
        $this->refunds->apply($refund, $event->toResult(), 'webhook');

        return WebhookResult::Applied;
    }
}
