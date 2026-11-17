<?php

namespace App\Services\Payments;

use App\Enums\ErrorCode;
use App\Enums\FulfilmentStatus;
use App\Enums\PayableType;
use App\Enums\PaymentMethodType;
use App\Enums\PaymentStatus;
use App\Enums\RefundKind;
use App\Exceptions\ApiException;
use App\Integrations\Payments\CardDetails;
use App\Integrations\Payments\ChargeRequest;
use App\Integrations\Payments\GatewayUnavailable;
use App\Integrations\Payments\PaymentGateway;
use App\Models\Payment;
use App\Models\SavedPaymentMethod;
use App\Models\User;
use App\Services\Availability\DeadlockRetry;
use App\Services\ServiceRequests\IdempotencyRace;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The one entry point for paying anything (slice-10-contract.md §4.2, D190). Replay first; then the handler's quote
 * (pre-check, nothing charged on failure); the attempt is inserted `pending` under the payer row lock and her older
 * open attempts on the same payable are cancelled (§6.4); the gateway is charged outside any transaction with the key
 * `payment:{id}`; the outcome goes through PaymentStateMachine; a success is fulfilled at once.
 */
class PaymentIntents
{
    private const MAX_REFERENCE_ATTEMPTS = 5;

    private const REFRESH_AFTER_SECONDS = 5;

    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly PayableRegistry $registry,
        private readonly PaymentStateMachine $machine,
        private readonly PaymentFulfilment $fulfilment,
        private readonly SavedPaymentMethods $methods,
        private readonly PaymentReferenceGenerator $references,
        private readonly PaymentSettings $settings,
        private readonly DeadlockRetry $retry,
    ) {}

    /**
     * @return array{0: Payment, 1: bool} the payment as it stands and whether it was created now (false = replay)
     */
    public function create(User $payer, PayableType $type, Model $payable, PaymentInstrument $instrument, string $idempotencyKey): array
    {
        $fingerprint = hash('sha256', $type->value.':'.$payable->getKey().':'.$instrument->fingerprint());
        if ($existing = $this->replay($payer, $idempotencyKey, $fingerprint)) {
            return [$this->refresh($existing), false];
        }

        $handler = $this->registry->for($type);
        $quote = $handler->quote($payer, $payable);
        [$token, $card, $saved] = $this->resolve($payer, $instrument);

        try {
            [$payment, $superseded] = $this->retry->run(fn () => DB::transaction(
                fn () => $this->insert($payer, $type, $payable, $quote, $instrument, $card, $saved, $idempotencyKey, $fingerprint)
            ), 'payment.create', ['user_id' => $payer->id, 'payable' => $type->value.':'.$payable->getKey()]);
        } catch (IdempotencyRace) {
            return [$this->refresh($this->replay($payer, $idempotencyKey, $fingerprint) ?? throw new ApiException(ErrorCode::ServerError)), false];
        }

        foreach ($superseded as $old) {
            $this->cancelAtGateway($old);
        }

        try {
            $result = $this->gateway->charge(new ChargeRequest(
                Money::normalize($payment->amount), $payment->currency, $token, 'payment:'.$payment->id, $payment->reference,
                $instrument->type->value, ['payable' => $type->value, 'payable_id' => $payable->getKey(), ...$quote->metadata],
            ));
            $outcome = GatewayOutcome::fromResult($result);
        } catch (GatewayUnavailable) {
            $outcome = GatewayOutcome::unknown();
        }

        $payment = $this->settle($this->machine->apply($payment, $outcome, 'charge'));

        if ($payment->status === PaymentStatus::Succeeded && $instrument->save && $instrument->token !== null && $card !== null) {
            $this->methods->remember($payer, $instrument->token, new CardDetails($card['brand'], $card['last4'], $card['exp_month'], $card['exp_year']));
        }

        return [$payment, true];
    }

    /**
     * Asks the gateway when a payment is still open and was not checked in the last few seconds; retries a pending
     * fulfilment. Used by `GET client/payments/{id}` and the reconcile command.
     */
    public function refresh(Payment $payment, bool $force = false): Payment
    {
        $payment = $payment->fresh();
        $stale = $force || $payment->last_checked_at === null || $payment->last_checked_at->lte(now()->subSeconds(self::REFRESH_AFTER_SECONDS));
        if (in_array($payment->status, PaymentStatus::open(), true) && $stale) {
            try {
                $result = $payment->gateway_reference
                    ? $this->gateway->status($payment->gateway_reference)
                    : $this->gateway->statusByKey('payment:'.$payment->id);
                if ($result !== null) {
                    $payment = $this->machine->apply($payment, GatewayOutcome::fromResult($result), 'reconcile');
                }
            } catch (GatewayUnavailable) {
                // Still unknown; asked again later.
            }
        }

        return $this->settle($payment);
    }

    /**
     * Expires an open attempt past its lifetime (D191): asks the gateway first, then cancels.
     */
    public function expire(Payment $payment): Payment
    {
        $payment = $this->refresh($payment, true);
        if (! in_array($payment->status, PaymentStatus::open(), true)) {
            return $payment;
        }
        $this->cancelAtGateway($payment);

        return $this->machine->apply($payment, GatewayOutcome::cancelled(), 'expiry');
    }

    /**
     * What follows a status change: fulfil a fresh success, refund a late success of an abandoned attempt (§6.4).
     */
    public function settle(Payment $payment): Payment
    {
        if ($payment->status !== PaymentStatus::Succeeded) {
            return $payment;
        }
        if ($payment->fulfilment_status === FulfilmentStatus::Pending) {
            return $this->fulfilment->run($payment);
        }
        if ($payment->fulfilment_status === FulfilmentStatus::NotApplicable && $payment->refundableCents() > 0
            && ! $payment->refunds()->where('kind', RefundKind::SupersededAttempt)->exists()) {
            $this->fulfilment->refundInFull($payment, RefundKind::SupersededAttempt);

            return $payment->fresh();
        }

        return $payment;
    }

    /**
     * @return array{0: string, 1: array|null, 2: SavedPaymentMethod|null} token, card snapshot, saved method
     */
    private function resolve(User $payer, PaymentInstrument $instrument): array
    {
        if ($instrument->savedMethodId !== null) {
            $saved = $this->methods->find($payer, $instrument->savedMethodId);
            if ($saved->isExpired()) {
                throw new ApiException(ErrorCode::PaymentMethodInvalid, null, ['payment_method_id' => [__('payments.failure.expired_card')]]);
            }

            return [$saved->gateway_token, $saved->snapshot(), $saved];
        }
        $token = $instrument->token ?? throw new ApiException(ErrorCode::ValidationFailed);
        if ($instrument->type === PaymentMethodType::ApplePay) {
            return [$token, null, null];
        }

        return [$token, $this->methods->describe($token)->toArray(), null];
    }

    /**
     * @return array{0: Payment, 1: list<Payment>} the new attempt and the older open attempts it replaced
     */
    private function insert(User $payer, PayableType $type, Model $payable, PaymentQuote $quote, PaymentInstrument $instrument, ?array $card, ?SavedPaymentMethod $saved, string $key, string $fingerprint): array
    {
        User::query()->whereKey($payer->id)->lockForUpdate()->first(['id']);
        if (Payment::query()->where('user_id', $payer->id)->where('idempotency_key', $key)->lockForUpdate()->exists()) {
            throw new IdempotencyRace;
        }

        $open = Payment::query()->where('user_id', $payer->id)->where('payable_type', $type->value)->where('payable_id', $payable->getKey())
            ->whereIn('status', PaymentStatus::open())->orderBy('id')->lockForUpdate()->get();
        $superseded = [];
        foreach ($open as $old) {
            $superseded[] = $this->machine->apply($old, GatewayOutcome::cancelled(), 'supersede');
        }

        $ttl = $this->settings->intentTtlMinutes();
        $payment = $this->insertWithReference([
            'user_id' => $payer->id,
            'payable_type' => $type,
            'payable_id' => $payable->getKey(),
            'purpose' => $quote->purpose,
            'amount' => Money::normalize($quote->amount),
            'currency' => config('app.currency'),
            'method' => $instrument->type,
            'card' => $card,
            'saved_payment_method_id' => $saved?->id,
            'gateway' => $this->gateway->name(),
            'status' => PaymentStatus::Pending,
            'fulfilment_status' => FulfilmentStatus::Pending,
            'refunded_amount' => '0.00',
            'idempotency_key' => $key,
            'idempotency_fingerprint' => $fingerprint,
            'expires_at' => $ttl !== null ? now()->addMinutes($ttl) : null,
        ]);

        return [$payment, $superseded];
    }

    private function insertWithReference(array $attributes): Payment
    {
        for ($attempt = 1; $attempt <= self::MAX_REFERENCE_ATTEMPTS; $attempt++) {
            try {
                return Payment::query()->create([...$attributes, 'reference' => $this->references->payment()]);
            } catch (UniqueConstraintViolationException $e) {
                if (str_contains($e->getMessage(), 'payments_user_id_idempotency_key_unique')) {
                    throw new IdempotencyRace;
                }
                if (! str_contains($e->getMessage(), 'payments_reference_unique')) {
                    throw $e;
                }
            }
        }

        Log::error('Payment reference generation exhausted its retries.');

        throw new ApiException(ErrorCode::ServerError);
    }

    private function cancelAtGateway(Payment $payment): void
    {
        if (! $payment->gateway_reference) {
            return;
        }
        try {
            $this->gateway->cancel($payment->gateway_reference);
        } catch (Throwable) {
            Log::warning('Gateway cancel failed (best effort).', ['payment_id' => $payment->id]);
        }
    }

    private function replay(User $payer, string $key, string $fingerprint): ?Payment
    {
        $existing = Payment::query()->where('user_id', $payer->id)->where('idempotency_key', $key)->first();
        if ($existing && ! hash_equals($existing->idempotency_fingerprint, $fingerprint)) {
            throw new ApiException(ErrorCode::DuplicateRequest);
        }

        return $existing;
    }
}
