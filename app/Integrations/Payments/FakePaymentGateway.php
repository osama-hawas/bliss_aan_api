<?php

namespace App\Integrations\Payments;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Test driver (D186, slice-10-contract.md §11). State lives in the cache store so it survives across requests and
 * queue jobs. Tokens: `tok_fake_<brand>_<last4>_<mmyy>` (mada/visa/mastercard) and `tok_fake_applepay`.
 *
 * last4 0002 → declined · 9995 → insufficient funds · 3155 → requires action (fake checkout page) ·
 * 0119 → the charge call times out but the charge succeeded · 0051 → refunds fail · past expiry → invalid token.
 */
class FakePaymentGateway implements PaymentGateway
{
    public const TOKEN_PATTERN = '/^tok_fake_(mada|visa|mastercard)_(\d{4})_(\d{2})(\d{2})$/';

    public const APPLE_PAY_TOKEN = 'tok_fake_applepay';

    public const SIGNATURE_HEADER = 'X-Fake-Signature';

    public const SIGNATURE_TOLERANCE_SECONDS = 300;

    private const PREFIX = 'fake_gateway:';

    public function name(): string
    {
        return 'fake';
    }

    public function describeToken(string $token): CardDetails
    {
        if (! preg_match(self::TOKEN_PATTERN, $token, $m)) {
            throw new InvalidPaymentToken('Unknown fake token.');
        }
        [$brand, $last4, $month, $year] = [$m[1], $m[2], (int) $m[3], 2000 + (int) $m[4]];
        if ($month < 1 || $month > 12) {
            throw new InvalidPaymentToken('Invalid expiry.');
        }
        $now = now('Asia/Riyadh');
        if ($year < $now->year || ($year === $now->year && $month < $now->month)) {
            throw new InvalidPaymentToken('Card expired.');
        }

        return new CardDetails($brand, $last4, $month, $year);
    }

    public function charge(ChargeRequest $request): GatewayResult
    {
        $keyed = Cache::get(self::PREFIX.'key:'.$request->idempotencyKey);
        if ($keyed !== null) {
            return $this->status($keyed);
        }

        $reference = 'fake_ch_'.Str::lower(Str::random(20));
        $last4 = $this->last4($request->token);
        $isApplePay = $request->token === self::APPLE_PAY_TOKEN;

        if (! $isApplePay) {
            try {
                $this->describeToken($request->token);
            } catch (InvalidPaymentToken) {
                return $this->store($request, $reference, GatewayResult::FAILED, 'expired_card', $last4);
            }
        }

        return match ($last4) {
            '0002' => $this->store($request, $reference, GatewayResult::FAILED, 'declined', $last4),
            '9995' => $this->store($request, $reference, GatewayResult::FAILED, 'insufficient_funds', $last4),
            '3155' => $this->store($request, $reference, GatewayResult::REQUIRES_ACTION, null, $last4),
            '0119' => $this->timeout($request, $reference, $last4),
            default => $this->store($request, $reference, GatewayResult::SUCCEEDED, null, $last4),
        };
    }

    public function cancel(string $gatewayReference): void
    {
        $charge = Cache::get(self::PREFIX.'charge:'.$gatewayReference);
        if ($charge && in_array($charge['status'], [GatewayResult::REQUIRES_ACTION, GatewayResult::PENDING], true)) {
            $charge['status'] = GatewayResult::CANCELLED;
            Cache::forever(self::PREFIX.'charge:'.$gatewayReference, $charge);
        }
    }

    public function status(string $gatewayReference): GatewayResult
    {
        $charge = Cache::get(self::PREFIX.'charge:'.$gatewayReference);
        if (! $charge) {
            return new GatewayResult(GatewayResult::FAILED, $gatewayReference, [], null, 'gateway_error');
        }

        return $this->result($gatewayReference, $charge);
    }

    public function statusByKey(string $idempotencyKey): ?GatewayResult
    {
        $reference = Cache::get(self::PREFIX.'key:'.$idempotencyKey);

        return $reference === null ? null : $this->status($reference);
    }

    public function refund(string $gatewayReference, string $amount, string $idempotencyKey): GatewayResult
    {
        $keyed = Cache::get(self::PREFIX.'refund_key:'.$idempotencyKey);
        if ($keyed !== null) {
            return $this->refundStatus($keyed);
        }
        $charge = Cache::get(self::PREFIX.'charge:'.$gatewayReference);
        $reference = 'fake_re_'.Str::lower(Str::random(20));
        // Our server decides what is refundable; the fake only fails unknown charges and the 0051 test card.
        $failed = ! $charge || ($charge['last4'] ?? null) === '0051';
        $refund = ['status' => $failed ? GatewayResult::FAILED : GatewayResult::SUCCEEDED, 'charge' => $gatewayReference,
            'amount' => $amount, 'failure_code' => $failed ? 'gateway_error' : null];
        Cache::forever(self::PREFIX.'refund:'.$reference, $refund);
        Cache::forever(self::PREFIX.'refund_key:'.$idempotencyKey, $reference);

        return new GatewayResult($refund['status'], $reference, ['charge' => $gatewayReference, 'amount' => $amount], null, $refund['failure_code']);
    }

    public function refundStatus(string $gatewayRefundReference): GatewayResult
    {
        $refund = Cache::get(self::PREFIX.'refund:'.$gatewayRefundReference);
        if (! $refund) {
            return new GatewayResult(GatewayResult::FAILED, $gatewayRefundReference, [], null, 'gateway_error');
        }

        return new GatewayResult($refund['status'], $gatewayRefundReference, ['charge' => $refund['charge'], 'amount' => $refund['amount']], null, $refund['failure_code']);
    }

    public function deleteToken(string $token): void
    {
        // Nothing is stored for a fake token.
    }

    public function parseWebhook(Request $request): WebhookEvent
    {
        if ((string) config('services.payments.webhook_secret') === '') {
            throw new InvalidWebhookSignature('No webhook secret configured.');
        }
        $header = (string) $request->header(self::SIGNATURE_HEADER, '');
        if (! preg_match('/^t=(\d+),v1=([a-f0-9]{64})$/', $header, $m)) {
            throw new InvalidWebhookSignature('Missing or malformed signature.');
        }
        $body = $request->getContent();
        if (abs(now()->getTimestamp() - (int) $m[1]) > self::SIGNATURE_TOLERANCE_SECONDS) {
            throw new InvalidWebhookSignature('Timestamp outside the tolerance.');
        }
        if (! hash_equals(self::sign($m[1], $body), $m[2])) {
            throw new InvalidWebhookSignature('Signature mismatch.');
        }
        $payload = json_decode($body, true);
        if (! is_array($payload) || ! is_string($payload['id'] ?? null) || ! is_string($payload['type'] ?? null)) {
            throw new InvalidWebhookSignature('Malformed payload.');
        }

        return new WebhookEvent($payload['id'], $payload['type'], $payload['data']['reference'] ?? null,
            $payload['data']['failure_code'] ?? null, $payload, $payload['data']['payment_reference'] ?? null);
    }

    // ---- Test helpers (fake checkout page, payments:fake-webhook, tests) ----

    public static function sign(string $timestamp, string $body): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$body, (string) config('services.payments.webhook_secret'));
    }

    /**
     * A signed delivery for `$type` about `$gatewayReference`: [body, headers].
     *
     * @return array{0: string, 1: array<string, string>}
     */
    public static function webhook(string $type, ?string $gatewayReference, ?string $failureCode = null, ?string $eventId = null, ?Carbon $at = null, ?string $paymentReference = null): array
    {
        $body = json_encode([
            'id' => $eventId ?? 'evt_'.Str::lower(Str::random(24)),
            'type' => $type,
            'data' => array_filter(['reference' => $gatewayReference, 'payment_reference' => $paymentReference, 'failure_code' => $failureCode]),
        ], JSON_THROW_ON_ERROR);
        $t = (string) ($at ?? now())->getTimestamp();

        return [$body, [self::SIGNATURE_HEADER => "t={$t},v1=".self::sign($t, $body), 'Content-Type' => 'application/json']];
    }

    /**
     * The bank answer of the fake checkout page: settles an open charge.
     */
    public function settle(string $gatewayReference, bool $approve): void
    {
        $charge = Cache::get(self::PREFIX.'charge:'.$gatewayReference);
        if (! $charge || ! in_array($charge['status'], [GatewayResult::REQUIRES_ACTION, GatewayResult::PENDING], true)) {
            return;
        }
        $charge['status'] = $approve ? GatewayResult::SUCCEEDED : GatewayResult::FAILED;
        $charge['failure_code'] = $approve ? null : 'authentication_failed';
        Cache::forever(self::PREFIX.'charge:'.$gatewayReference, $charge);
    }

    public static function token(string $brand, string $last4, int $month, int $year): string
    {
        return sprintf('tok_fake_%s_%s_%02d%02d', $brand, $last4, $month, $year % 100);
    }

    private function last4(string $token): ?string
    {
        return preg_match(self::TOKEN_PATTERN, $token, $m) ? $m[2] : null;
    }

    private function store(ChargeRequest $request, string $reference, string $status, ?string $failureCode, ?string $last4): GatewayResult
    {
        $charge = ['status' => $status, 'amount' => $request->amount, 'currency' => $request->currency,
            'last4' => $last4, 'failure_code' => $failureCode, 'payment' => $request->reference];
        Cache::forever(self::PREFIX.'charge:'.$reference, $charge);
        Cache::forever(self::PREFIX.'key:'.$request->idempotencyKey, $reference);

        return $this->result($reference, $charge);
    }

    private function timeout(ChargeRequest $request, string $reference, ?string $last4): never
    {
        $this->store($request, $reference, GatewayResult::SUCCEEDED, null, $last4);

        throw new GatewayUnavailable('Fake timeout; the charge succeeded at the gateway.');
    }

    private function result(string $reference, array $charge): GatewayResult
    {
        $actionUrl = $charge['status'] === GatewayResult::REQUIRES_ACTION
            ? rtrim((string) config('app.url'), '/').'/webhooks/payments/fake/checkout/'.$reference
            : null;

        return new GatewayResult($charge['status'], $reference, [
            'amount' => $charge['amount'], 'currency' => $charge['currency'], 'payment' => $charge['payment'],
        ], $actionUrl, $charge['failure_code']);
    }
}
