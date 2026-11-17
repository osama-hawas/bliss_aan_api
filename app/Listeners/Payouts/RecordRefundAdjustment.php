<?php

namespace App\Listeners\Payouts;

use App\Events\Payments\RefundSettled;
use App\Services\Payouts\RefundAdjustments;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Slice 10's after-commit `RefundSettled` (refundId, paymentId, status) → the provider's refund adjustment
 * (slice-11-contract.md §4.2, D220). Never fails the refund: a lost run is repaired by `payouts:record-earnings`.
 */
class RecordRefundAdjustment
{
    public function __construct(private readonly RefundAdjustments $adjustments) {}

    public function handle(RefundSettled $event): void
    {
        // The refund row's own status is checked (only `refunded` writes anything), so a `failed` event is a no-op.
        try {
            $this->adjustments->forRefund((int) $event->refundId);
        } catch (Throwable $e) {
            Log::error('[payouts] refund adjustment failed; the back-fill command will retry', ['refund_id' => $event->refundId, 'error' => $e->getMessage()]);
        }
    }
}
