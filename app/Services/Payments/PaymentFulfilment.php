<?php

namespace App\Services\Payments;

use App\Enums\ActivityVisibility;
use App\Enums\FulfilmentStatus;
use App\Enums\PaymentStatus;
use App\Enums\RefundKind;
use App\Events\Payments\PaymentFulfilled;
use App\Exceptions\ApiException;
use App\Models\Payment;
use App\Services\Audit\ActivityRecorder;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * After a successful payment (slice-10-contract.md §4.2): runs the handler's fulfil() once. An ApiException means the
 * payable can no longer be delivered (e.g. the slot was taken) → fulfilment `failed` + automatic full refund (D185).
 * Any other error leaves it `pending`; the reconcile command retries (handlers are idempotent on the payment id).
 */
class PaymentFulfilment
{
    public function __construct(
        private readonly PayableRegistry $registry,
        private readonly PaymentRefunds $refunds,
        private readonly ActivityRecorder $activity,
    ) {}

    public function run(Payment $payment): Payment
    {
        $payment = $payment->fresh();
        if ($payment->status !== PaymentStatus::Succeeded || $payment->fulfilment_status !== FulfilmentStatus::Pending) {
            return $payment;
        }

        try {
            $model = $this->registry->for($payment->payable_type)->fulfil($payment);
        } catch (ApiException $e) {
            $this->mark($payment, FulfilmentStatus::Failed, $e->errorCode->value);
            $this->refundInFull($payment, RefundKind::AcceptanceFailed);

            return $payment->fresh();
        } catch (Throwable $e) {
            Log::error('Payment fulfilment failed; it stays pending for the reconcile command.', ['payment_id' => $payment->id, 'exception' => $e::class, 'message' => $e->getMessage()]);

            return $payment->fresh();
        }

        $this->mark($payment, FulfilmentStatus::Fulfilled, null, $model?->getKey());

        return $payment->fresh();
    }

    /**
     * Full refund of whatever is still refundable, once per kind (idempotency key `auto:{kind}`).
     */
    public function refundInFull(Payment $payment, RefundKind $kind): void
    {
        $payment = $payment->fresh();
        $cents = $payment->refundableCents();
        if ($cents <= 0) {
            return;
        }
        try {
            $this->refunds->refund($payment, Money::fromCents($cents), $kind, null, null, 'auto:'.$kind->value);
        } catch (ApiException $e) {
            Log::error('Automatic refund could not start.', ['payment_id' => $payment->id, 'kind' => $kind->value, 'code' => $e->errorCode->value]);
        }
    }

    private function mark(Payment $payment, FulfilmentStatus $status, ?string $error, int|string|null $fulfilledId = null): void
    {
        DB::transaction(function () use ($payment, $status, $error, $fulfilledId) {
            $locked = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($locked->fulfilment_status !== FulfilmentStatus::Pending) {
                return;
            }
            $locked->update(['fulfilment_status' => $status, 'fulfilment_error' => $error]);
            $this->activity->record($locked, 'payment_fulfilment_'.$status->value, FulfilmentStatus::Pending, $status, null,
                ActivityVisibility::Internal, array_filter(['error' => $error, 'fulfilled_id' => $fulfilledId]));
            if ($status === FulfilmentStatus::Fulfilled) {
                PaymentFulfilled::dispatch($locked->id, $locked->payable_type->value, $fulfilledId !== null ? (int) $fulfilledId : null);
            }
        });
    }
}
