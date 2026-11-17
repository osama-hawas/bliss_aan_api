<?php

namespace App\Listeners\Operations;

use App\Enums\NotificationEvent;
use App\Enums\NotificationTargetType;
use App\Services\Notifications\Notifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * E25 `refund_issued` (slice-17-contract.md §5.4, D263): when a **staff** refund settles `refunded`, the paying client
 * is told the amount. Automatic refunds (acceptance failed, superseded attempt) already show in the payment result and
 * send nothing (D194). Only booking payments are announced; other payables (store orders, S9b) keep their own events.
 *
 * Slice 10 dependency: the after-commit event `App\Events\Payments\RefundSettled` (`refundId`, `paymentId`, `status`) and
 * the `refunds` / `payments` / `bookings.payment_id` columns (slice-10-contract.md §4.4, §5). Registered by class name
 * in AppServiceProvider so this slice compiles before S10 is merged. The handler parameter is untyped on purpose.
 */
class NotifyRefundIssued
{
    public function __construct(private readonly Notifier $notifier) {}

    public function handle($event): void
    {
        $status = $event->status ?? null;
        $status = $status instanceof \BackedEnum ? $status->value : $status;
        if ($status !== 'refunded' || ! Schema::hasTable('refunds')) {
            return;
        }

        $refund = DB::table('refunds')->where('id', $event->refundId)->first(['id', 'payment_id', 'kind', 'amount', 'status']);
        if (! $refund || $refund->kind !== 'staff' || $refund->status !== 'refunded') {
            return;
        }

        $payment = DB::table('payments')->where('id', $refund->payment_id)->first(['id', 'user_id', 'currency']);
        $booking = DB::table('bookings')->where('payment_id', $refund->payment_id)->first(['id', 'reference', 'client_id']);
        if (! $payment || ! $booking) {
            return;
        }

        $amount = number_format((float) $refund->amount, 2, '.', '').' '.($payment->currency ?: 'SAR');
        $this->notifier->send((int) ($payment->user_id ?? $booking->client_id), NotificationEvent::RefundIssued,
            ['reference' => $booking->reference, 'amount' => $amount], NotificationTargetType::Booking, (int) $booking->id,
            'refund_issued:'.$refund->id);
    }
}
