<?php

namespace App\Services\Store;

use App\Enums\StoreOrderStatus;
use App\Models\StoreOrder;
use App\Services\Settings\AppSettings;

/**
 * Expires unpaid orders whose payment time is over (D212/Q115, `slice-9b-contract.md` §7.4): older than the Slice 10
 * attempt lifetime `payments.intent_ttl_minutes` and with no open attempt. `null` lifetime → only a newer checkout
 * expires them. A payment that still succeeds later is refunded by the handler's `fulfil`.
 */
class StoreOrderExpiry
{
    public function __construct(
        private readonly StoreOrderLifecycle $lifecycle,
        private readonly AppSettings $settings,
    ) {}

    public function run(): int
    {
        $minutes = $this->settings->get('payments.intent_ttl_minutes');
        if ($minutes === null) {
            return 0;
        }

        $expired = 0;
        StoreOrder::query()
            ->where('status', StoreOrderStatus::PendingPayment)
            ->where('created_at', '<=', now()->subMinutes((int) $minutes))
            ->withoutLivePayment() // re-checked under the order lock in expire()
            ->orderBy('id')
            ->chunkById(100, function ($orders) use (&$expired) {
                foreach ($orders as $order) {
                    $expired += $this->lifecycle->expire($order) ? 1 : 0;
                }
            });

        return $expired;
    }
}
