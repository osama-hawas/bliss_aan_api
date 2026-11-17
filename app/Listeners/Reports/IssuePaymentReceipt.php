<?php

namespace App\Listeners\Reports;

use App\Events\Payments\PaymentSucceeded;
use App\Services\Reports\Receipts\PaymentReceipts;
use Throwable;

/**
 * D325: issue the receipt as soon as a payment succeeds, so its VAT snapshot is the one in force at payment time.
 * Listens to S10's after-commit `PaymentSucceeded` (payload `paymentId`, slice-10 contract §4.4); discovered
 * automatically once that event class exists. A failure here never affects the payment: the receipt is then issued at
 * the first download instead.
 */
class IssuePaymentReceipt
{
    public function __construct(private readonly PaymentReceipts $receipts) {}

    public function handle(PaymentSucceeded $event): void
    {
        try {
            $this->receipts->issue((int) $event->paymentId);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
