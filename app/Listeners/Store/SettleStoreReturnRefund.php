<?php

namespace App\Listeners\Store;

use App\Models\Refund;
use App\Services\Store\Returns\StoreReturnProcessing;

/**
 * S23 (D345): when a Slice 10 refund started for a store return settles (gateway answer, `refund.*` webhook or the
 * reconcile command), the return moves to `refunded` (E59) or records the failure. `RefundSettled` is dispatched after
 * commit. Registered by class name in AppServiceProvider; the parameter is untyped so event discovery does not bind it
 * a second time.
 */
class SettleStoreReturnRefund
{
    public function __construct(private readonly StoreReturnProcessing $processing) {}

    public function handle($event): void
    {
        $refund = Refund::query()->find($event->refundId ?? 0);
        if ($refund === null || StoreReturnProcessing::returnIdFromKey((string) $refund->idempotency_key) === null) {
            return;
        }
        $this->processing->settle($refund);
    }
}
