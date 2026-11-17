<?php

namespace App\Services\Payments;

use App\Enums\ActivityVisibility;
use App\Enums\RefundStatus;
use App\Events\Payments\RefundSettled;
use App\Integrations\Payments\GatewayResult;
use App\Models\Payment;
use App\Models\Refund;
use App\Services\Audit\ActivityRecorder;
use App\Services\Availability\DeadlockRetry;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Settles a refund once (gateway answer or `refund.*` webhook). Lock order (§9): the payable's rows
 * (PayableHandler::lockForRefund) → payment → refund. A failed refund releases its reserved amount.
 */
class RefundStateMachine
{
    public function __construct(
        private readonly PayableRegistry $registry,
        private readonly ActivityRecorder $activity,
        private readonly DeadlockRetry $retry,
    ) {}

    public function apply(Refund $refund, GatewayResult $result, string $source): Refund
    {
        return $this->retry->run(fn () => DB::transaction(function () use ($refund, $result, $source) {
            $payment = Payment::query()->findOrFail($refund->payment_id);
            $handler = $this->registry->for($payment->payable_type);
            $handler->lockForRefund($payment);
            $payment = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            $locked = Refund::query()->whereKey($refund->id)->lockForUpdate()->firstOrFail();

            if ($result->reference !== null && $locked->gateway_reference === null) {
                $locked->gateway_reference = $result->reference;
            }
            if ($locked->status->isTerminal() || ! in_array($result->status, [GatewayResult::SUCCEEDED, GatewayResult::FAILED], true)) {
                $locked->save();

                return $locked;
            }

            $from = $locked->status;
            $locked->processed_at = now();
            if ($result->status === GatewayResult::SUCCEEDED) {
                $locked->status = RefundStatus::Refunded;
                $locked->save();
                $payment->unsetRelation('refunds');
                $handler->onRefunded($payment, $locked);
            } else {
                $locked->status = RefundStatus::Failed;
                $locked->failure_code = $result->failureCode ?? 'gateway_error';
                $locked->save();
                $payment->refunded_amount = Money::fromCents(max(0, $payment->refundedCents() - Money::toCents($locked->amount)));
                $payment->save();
            }

            $this->activity->record($payment, 'refund_'.$locked->status->value, $from, $locked->status, null, ActivityVisibility::Internal,
                ['refund_id' => $locked->id, 'amount' => Money::normalize($locked->amount), 'source' => $source]);
            RefundSettled::dispatch($locked->id, $payment->id, $locked->status->value);

            return $locked;
        }), 'refund.apply', ['refund_id' => $refund->id, 'source' => $source]);
    }
}
