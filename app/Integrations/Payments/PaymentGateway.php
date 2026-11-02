<?php

namespace App\Integrations\Payments;

/**
 * Payment gateway is undecided (Q8) and the payment plan is open (Q1).
 * Card data never reaches this API: the apps tokenize with the gateway SDK.
 */
interface PaymentGateway
{
    /**
     * Start a charge. `$idempotencyKey` is forwarded so retries never double-charge.
     */
    public function charge(string $amount, string $currency, string $token, string $idempotencyKey, array $metadata = []): GatewayResult;

    public function refund(string $gatewayReference, string $amount, string $idempotencyKey): GatewayResult;

    /**
     * Look up the live status (used when a result is `unknown`).
     */
    public function status(string $gatewayReference): GatewayResult;
}
