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
use App\Services\Wallets\WalletLedger;
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
        private readonly WalletLedger $walletLedger,
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

        // §6.4: her older open attempts on this payable are asked first and cancelled only when the gateway answers.
        // An attempt whose outcome is still unknown stays open for the reconcile command (never cancelled blind).
        Payment::query()->where('user_id', $payer->id)->where('payable_type', $type->value)->where('payable_id', $payable->getKey())
            ->whereIn('status', PaymentStatus::open())->orderBy('id')->get()
            ->each(fn (Payment $old) => $this->retire($old));

        // The quote runs after the older attempts settled: if one of them just confirmed the payable, nothing is charged.
        $handler = $this->registry->for($type);
        $quote = $handler->quote($payer, $payable);
        if ($instrument->type === PaymentMethodType::Wallet) {
            return $this->payFromWallet($payer, $type, $payable, $quote, $instrument, $idempotencyKey, $fingerprint);
        }
        [$token, $card, $saved] = $this->resolve($payer, $instrument);

        try {
            $payment = $this->retry->run(fn () => DB::transaction(
                fn () => $this->insert($payer, $type, $payable, $quote, $instrument, $card, $saved, $idempotencyKey, $fingerprint)
            ), 'payment.create', ['user_id' => $payer->id, 'payable' => $type->value.':'.$payable->getKey()]);
        } catch (IdempotencyRace) {
            return [$this->refresh($this->replay($payer, $idempotencyKey, $fingerprint) ?? throw new ApiException(ErrorCode::ServerError)), false];
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
     * Owner decision 2026-11-19: paid from her wallet, no gateway. The payment row and the wallet debit are written in
     * one transaction (wallet row locked: `409 INSUFFICIENT_BALANCE` when it does not cover the total), then the payment
     * is settled like a gateway success, so fulfilment, earnings and refunds work the same way.
     *
     * @return array{0: Payment, 1: bool}
     */
    private function payFromWallet(User $payer, PayableType $type, Model $payable, PaymentQuote $quote, PaymentInstrument $instrument, string $key, string $fingerprint): array
    {
        try {
            $payment = $this->retry->run(fn () => DB::transaction(function () use ($payer, $type, $payable, $quote, $instrument, $key, $fingerprint) {
                $payment = $this->insert($payer, $type, $payable, $quote, $instrument, null, null, $key, $fingerprint);
                $payment->forceFill(['gateway' => 'wallet'])->save();
                $this->walletLedger->debit($payer, $payment->amount, WalletLedger::PAYMENT, $payment, ['payable' => $type->value, 'payable_id' => $payable->getKey()]);

                return $payment;
            }), 'payment.wallet', ['user_id' => $payer->id, 'payable' => $type->value.':'.$payable->getKey()]);
        } catch (IdempotencyRace) {
            return [$this->refresh($this->replay($payer, $key, $fingerprint) ?? throw new ApiException(ErrorCode::ServerError)), false];
        }

        $outcome = new GatewayOutcome(PaymentStatus::Succeeded, 'wallet:'.$payment->id);

        return [$this->settle($this->machine->apply($payment, $outcome, 'wallet')), true];
    }

    /**
     * Asks the gateway when a payment is still open and was not checked in the last few seconds; retries a pending
     * fulfilment. Used by `GET client/payments/{id}` and the reconcile command.
     */
    public function refresh(Payment $payment, bool $force = false): Payment
    {
        $payment = $payment->fresh();
        if ($payment->gateway === 'wallet') {
            return $this->settle($payment); // nothing to ask: wallet payments are final at once
        }
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
     * Expires an open attempt past its lifetime (D191). Same rule as superseding: see retire().
     */
    public function expire(Payment $payment): Payment
    {
        return $this->retire($payment, 'expiry');
    }

    /**
     * Cancels an open attempt only once the gateway has answered about it (review H1): the gateway is asked first
     * (by reference, or by our idempotency key when the charge call gave none). If it cannot be reached the attempt
     * stays open (`unknown`) and the reconcile command asks again — captured money is never left unrecorded. A known
     * outcome is applied; a still-open charge is cancelled at the gateway and here (a late success is then matched by
     * its reference and refunded, §6.4).
     */
    public function retire(Payment $payment, string $source = 'supersede'): Payment
    {
        $payment = $payment->fresh();
        if ($payment->gateway === 'wallet') {
            return $this->settle($payment);
        }
        if (! in_array($payment->status, PaymentStatus::open(), true)) {
            return $this->settle($payment);
        }
        try {
            $result = $payment->gateway_reference
                ? $this->gateway->status($payment->gateway_reference)
                : $this->gateway->statusByKey('payment:'.$payment->id);
        } catch (GatewayUnavailable) {
            Log::warning('Open payment not cancelled: the gateway could not be asked.', ['payment_id' => $payment->id, 'source' => $source]);

            return $payment;
        }
        if ($result !== null) {
            $payment = $this->machine->apply($payment, GatewayOutcome::fromResult($result), 'reconcile');
            if (! in_array($payment->status, PaymentStatus::open(), true)) {
                return $this->settle($payment);
            }
        }
        $this->cancelAtGateway($payment);

        return $this->machine->apply($payment, GatewayOutcome::cancelled(), $source);
    }

    /**
     * Review H1: a cancelled attempt whose charge call never returned a reference is asked once more by key, so a
     * charge the gateway did take is recorded (and then refunded, §6.4).
     */
    public function recheckCancelled(Payment $payment): Payment
    {
        if ($payment->status !== PaymentStatus::Cancelled || $payment->gateway_reference) {
            return $payment;
        }
        try {
            $result = $this->gateway->statusByKey('payment:'.$payment->id);
        } catch (GatewayUnavailable) {
            return $payment;
        }
        $payment->forceFill(['last_checked_at' => now()])->save();

        return $result === null ? $payment : $this->settle($this->machine->apply($payment, GatewayOutcome::fromResult($result), 'reconcile'));
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
        // Money of a success that was never delivered goes back in full; a failed automatic refund is tried again (M3).
        if (in_array($payment->fulfilment_status, [FulfilmentStatus::NotApplicable, FulfilmentStatus::Failed], true) && $payment->refundableCents() > 0) {
            $this->fulfilment->refundInFull($payment, $payment->fulfilment_status === FulfilmentStatus::Failed
                ? RefundKind::AcceptanceFailed : RefundKind::SupersededAttempt);

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

    private function insert(User $payer, PayableType $type, Model $payable, PaymentQuote $quote, PaymentInstrument $instrument, ?array $card, ?SavedPaymentMethod $saved, string $key, string $fingerprint): Payment
    {
        User::query()->whereKey($payer->id)->lockForUpdate()->first(['id']);
        if (Payment::query()->where('user_id', $payer->id)->where('idempotency_key', $key)->lockForUpdate()->exists()) {
            throw new IdempotencyRace;
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

        return $payment;
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
