<?php

namespace App\Services\Payments;

use App\Enums\ActivityVisibility;
use App\Enums\ErrorCode;
use App\Enums\FulfilmentStatus;
use App\Enums\PaymentStatus;
use App\Enums\RefundKind;
use App\Enums\RefundStatus;
use App\Exceptions\ApiException;
use App\Integrations\Payments\GatewayUnavailable;
use App\Integrations\Payments\PaymentGateway;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\User;
use App\Services\Audit\ActivityRecorder;
use App\Services\Audit\AuditLogger;
use App\Services\Availability\DeadlockRetry;
use App\Services\ServiceRequests\IdempotencyRace;
use App\Support\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Refunds (D187, slice-10-contract.md §4.2). The amount is reserved `in_progress` under the payment row lock (so two
 * refunds can never exceed the payment), the gateway is called outside the transaction with the key `refund:{id}`,
 * and RefundStateMachine settles it. Staff refunds need a reason and write an audit row; automatic ones (D185, §6.4)
 * use a fixed localized reason.
 */
class PaymentRefunds
{
    private const MAX_REFERENCE_ATTEMPTS = 5;

    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly PayableRegistry $registry,
        private readonly RefundStateMachine $machine,
        private readonly PaymentReferenceGenerator $references,
        private readonly ActivityRecorder $activity,
        private readonly AuditLogger $audit,
        private readonly DeadlockRetry $retry,
    ) {}

    /**
     * @return array{0: Refund, 1: bool} the refund and whether it was created now (false = replay)
     */
    public function refund(Payment $payment, string $amount, RefundKind $kind, ?User $staff, ?string $reason, string $idempotencyKey, ?int $cancellationRequestId = null): array
    {
        $cents = Money::toCents($amount);
        if ($cents <= 0) {
            throw new ApiException(ErrorCode::ValidationFailed, null, ['amount' => [__('validation.gt.numeric', ['attribute' => 'amount', 'value' => 0])]]);
        }
        $reason ??= __('payments.refund_reason.'.$kind->value);

        if ($existing = $this->replay($payment, $idempotencyKey, $cents)) {
            return [$existing, false];
        }

        try {
            $refund = $this->retry->run(fn () => DB::transaction(
                fn () => $this->reserve($payment, $cents, $kind, $staff, $reason, $idempotencyKey, $cancellationRequestId)
            ), 'refund.create', ['payment_id' => $payment->id, 'kind' => $kind->value]);
        } catch (IdempotencyRace) {
            return [$this->replay($payment, $idempotencyKey, $cents) ?? throw new ApiException(ErrorCode::ServerError), false];
        }

        return [$this->send($refund), true];
    }

    /**
     * Calls the gateway for an `in_progress` refund (also used by the reconcile command when the first call failed).
     */
    public function send(Refund $refund): Refund
    {
        $payment = Payment::query()->findOrFail($refund->payment_id);
        try {
            $result = $refund->gateway_reference
                ? $this->gateway->refundStatus($refund->gateway_reference)
                : $this->gateway->refund((string) $payment->gateway_reference, Money::normalize($refund->amount), 'refund:'.$refund->id);
        } catch (GatewayUnavailable $e) {
            Log::warning('Refund gateway call failed; the reconcile command retries it.', ['refund_id' => $refund->id]);

            return $refund->fresh();
        }

        return $this->machine->apply($refund, $result, 'gateway');
    }

    private function reserve(Payment $payment, int $cents, RefundKind $kind, ?User $staff, string $reason, string $key, ?int $cancellationRequestId): Refund
    {
        $this->registry->for($payment->payable_type)->lockForRefund($payment);
        $locked = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
        if (Refund::query()->where('payment_id', $locked->id)->where('idempotency_key', $key)->exists()) {
            throw new IdempotencyRace;
        }
        if ($locked->status !== PaymentStatus::Succeeded || ! $locked->gateway_reference) {
            throw new ApiException(ErrorCode::InvalidState, __('errors.payment_not_refundable'));
        }
        if ($kind === RefundKind::Staff && $locked->fulfilment_status === FulfilmentStatus::Pending) {
            throw new ApiException(ErrorCode::InvalidState, __('errors.payment_fulfilment_pending')); // review L2
        }
        if ($cents > $locked->refundableCents()) {
            throw new ApiException(ErrorCode::RefundAmountExceeded);
        }

        $refund = $this->insert([
            'payment_id' => $locked->id,
            'kind' => $kind,
            'amount' => Money::fromCents($cents),
            'reason' => $reason,
            'status' => RefundStatus::InProgress,
            'initiated_by' => $staff?->id,
            'cancellation_request_id' => $cancellationRequestId,
            'idempotency_key' => $key,
        ]);
        $locked->refunded_amount = Money::fromCents($locked->refundedCents() + $cents);
        $locked->save();

        $this->activity->record($locked, 'refund_requested', null, RefundStatus::InProgress, $staff, ActivityVisibility::Internal,
            ['refund_id' => $refund->id, 'kind' => $kind->value, 'amount' => $refund->amount]);
        if ($staff) {
            $this->audit->log('payment_refunded', $staff, $locked, $reason, ['refunded_amount' => Money::fromCents($locked->refundedCents() - $cents)],
                ['refund_id' => $refund->id, 'amount' => $refund->amount, 'refunded_amount' => $locked->refunded_amount]);
        }

        return $refund;
    }

    private function insert(array $attributes): Refund
    {
        for ($attempt = 1; $attempt <= self::MAX_REFERENCE_ATTEMPTS; $attempt++) {
            try {
                return Refund::query()->create([...$attributes, 'reference' => $this->references->refund()]);
            } catch (UniqueConstraintViolationException $e) {
                if (str_contains($e->getMessage(), 'refunds_payment_id_idempotency_key_unique')) {
                    throw new IdempotencyRace;
                }
                if (! str_contains($e->getMessage(), 'refunds_reference_unique')) {
                    throw $e;
                }
            }
        }

        throw new ApiException(ErrorCode::ServerError);
    }

    private function replay(Payment $payment, string $key, int $cents): ?Refund
    {
        $existing = Refund::query()->where('payment_id', $payment->id)->where('idempotency_key', $key)->first();
        if ($existing && Money::toCents($existing->amount) !== $cents) {
            throw new ApiException(ErrorCode::DuplicateRequest);
        }

        return $existing;
    }
}
