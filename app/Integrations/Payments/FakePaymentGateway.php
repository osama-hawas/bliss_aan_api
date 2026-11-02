<?php

namespace App\Integrations\Payments;

use Illuminate\Support\Str;

/**
 * Succeeds every call, except the token `tok_fail`, which fails. Same idempotency key → same reference.
 */
class FakePaymentGateway implements PaymentGateway
{
    /** @var array<string, GatewayResult> */
    private array $byKey = [];

    public function charge(string $amount, string $currency, string $token, string $idempotencyKey, array $metadata = []): GatewayResult
    {
        return $this->byKey[$idempotencyKey] ??= new GatewayResult(
            $token === 'tok_fail' ? 'failed' : 'succeeded',
            'fake_ch_'.Str::random(16),
            ['amount' => $amount, 'currency' => $currency, 'metadata' => $metadata],
        );
    }

    public function refund(string $gatewayReference, string $amount, string $idempotencyKey): GatewayResult
    {
        return $this->byKey[$idempotencyKey] ??= new GatewayResult('succeeded', 'fake_re_'.Str::random(16), [
            'charge' => $gatewayReference,
            'amount' => $amount,
        ]);
    }

    public function status(string $gatewayReference): GatewayResult
    {
        return new GatewayResult('succeeded', $gatewayReference);
    }
}
