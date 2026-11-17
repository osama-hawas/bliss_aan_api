<?php

namespace App\Services\Payments;

use App\Enums\ActivityVisibility;
use App\Enums\FulfilmentStatus;
use App\Enums\PaymentStatus;
use App\Events\Payments\PaymentFailed;
use App\Events\Payments\PaymentSucceeded;
use App\Models\Payment;
use App\Services\Audit\ActivityRecorder;
use App\Services\Availability\DeadlockRetry;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of `payments.status` (slice-10-contract.md §4.2, §6). Every outcome source — the charge result,
 * a webhook, the reconcile command — goes through apply(). Forward-only: a terminal payment never moves, except a late
 * `succeeded` on a `cancelled` attempt, recorded with fulfilment `not_applicable` so it is refunded (§6.4).
 */
class PaymentStateMachine
{
    public function __construct(
        private readonly ActivityRecorder $activity,
        private readonly DeadlockRetry $retry,
    ) {}

    /**
     * @param  string  $source  charge | webhook | reconcile | supersede
     */
    public function apply(Payment $payment, GatewayOutcome $outcome, string $source): Payment
    {
        return $this->retry->run(fn () => DB::transaction(function () use ($payment, $outcome, $source) {
            $locked = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            $locked->last_checked_at = now();
            if ($outcome->reference !== null && $locked->gateway_reference === null) {
                $locked->gateway_reference = $outcome->reference;
            }

            $from = $locked->status;
            $to = $outcome->status;
            if (! $from->canMoveTo($to)) {
                if ($to === PaymentStatus::RequiresAction && $from === PaymentStatus::RequiresAction && $outcome->actionUrl) {
                    $locked->next_action_url = $outcome->actionUrl;
                }
                $locked->save();

                return $locked;
            }

            $now = now();
            $locked->status = $to;
            $locked->next_action_url = $to === PaymentStatus::RequiresAction ? $outcome->actionUrl : null;
            if ($outcome->raw) {
                $locked->raw_response = $outcome->raw;
            }
            match ($to) {
                PaymentStatus::Succeeded => $this->succeeded($locked, $from, $now),
                PaymentStatus::Failed => $locked->fill(['failure_code' => $outcome->failureCode ?? 'gateway_error', 'failed_at' => $now,
                    'fulfilment_status' => FulfilmentStatus::NotApplicable]),
                PaymentStatus::Cancelled => $locked->fill(['cancelled_at' => $now, 'fulfilment_status' => FulfilmentStatus::NotApplicable]),
                default => null,
            };
            $locked->save();

            $this->activity->record($locked, 'payment_'.$to->value, $from, $to, null, ActivityVisibility::Internal, ['source' => $source]);
            if ($to === PaymentStatus::Succeeded) {
                PaymentSucceeded::dispatch($locked->id);
            } elseif ($to->isTerminal()) {
                PaymentFailed::dispatch($locked->id);
            }

            return $locked;
        }), 'payment.apply', ['payment_id' => $payment->id, 'source' => $source]);
    }

    private function succeeded(Payment $payment, PaymentStatus $from, $now): void
    {
        $payment->paid_at = $now;
        $payment->failure_code = null;
        // A late success on an abandoned attempt is never fulfilled: it is refunded in full (§6.4).
        $payment->fulfilment_status = $from === PaymentStatus::Cancelled ? FulfilmentStatus::NotApplicable : FulfilmentStatus::Pending;
    }
}
