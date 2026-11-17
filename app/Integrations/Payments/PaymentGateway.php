<?php

namespace App\Integrations\Payments;

use Illuminate\Http\Request;

/**
 * Payment gateway seam (D186: no vendor chosen, `fake` driver only). Card data never reaches this API: the apps
 * tokenize with the gateway SDK and send only the token (D188). Calls are never made inside a DB transaction; every
 * money call carries an idempotency key so a retry never charges or refunds twice (slice-10-contract.md §9).
 */
interface PaymentGateway
{
    /**
     * Driver name stored on payments and saved methods (`fake`).
     */
    public function name(): string;

    /**
     * Brand, last four and expiry of a card token.
     *
     * @throws InvalidPaymentToken invalid, expired or unsupported card
     */
    public function describeToken(string $token): CardDetails;

    /**
     * Start a charge. Result status: succeeded | failed | requires_action | pending.
     *
     * @throws GatewayUnavailable timeout / network error: the outcome is unknown, ask status() later
     */
    public function charge(ChargeRequest $request): GatewayResult;

    /**
     * Best effort: abandon an open charge (no effect on a settled one).
     */
    public function cancel(string $gatewayReference): void;

    /**
     * Live status of a charge (used for `unknown` / open payments).
     *
     * @throws GatewayUnavailable
     */
    public function status(string $gatewayReference): GatewayResult;

    /**
     * Status of a charge looked up by our idempotency key, when the charge call itself timed out and gave no
     * reference. Null when the gateway never received it.
     *
     * @throws GatewayUnavailable
     */
    public function statusByKey(string $idempotencyKey): ?GatewayResult;

    /**
     * Refund part or all of a charge. Result status: succeeded | failed | pending.
     *
     * @throws GatewayUnavailable
     */
    public function refund(string $gatewayReference, string $amount, string $idempotencyKey): GatewayResult;

    /**
     * Live status of a refund.
     *
     * @throws GatewayUnavailable
     */
    public function refundStatus(string $gatewayRefundReference): GatewayResult;

    /**
     * Best effort: forget a saved token at the gateway.
     */
    public function deleteToken(string $token): void;

    /**
     * Verify the signature and read a webhook delivery (D189).
     *
     * @throws InvalidWebhookSignature
     */
    public function parseWebhook(Request $request): WebhookEvent;
}
