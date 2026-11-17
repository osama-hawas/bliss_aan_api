<?php

namespace App\Services\Store;

use App\Enums\ActivityVisibility;
use App\Enums\ErrorCode;
use App\Enums\NotificationEvent;
use App\Enums\NotificationTargetType;
use App\Enums\PromoCodeUsageStatus;
use App\Enums\StockMovementSource;
use App\Enums\StoreOrderStatus;
use App\Exceptions\ApiException;
use App\Models\CartItem;
use App\Models\ProductVariant;
use App\Models\PromoCode;
use App\Models\PromoCodeUsage;
use App\Models\StoreOrder;
use App\Models\User;
use App\Services\Audit\ActivityRecorder;
use App\Services\Audit\AuditLogger;
use App\Services\Availability\DeadlockRetry;
use App\Services\Notifications\Notifier;
use App\Services\Store\Admin\StockAdjustments;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Every store order state change (D207, D211, D212; `slice-9b-contract.md` §4.1, §7.3). One method per move: lock,
 * check the transition, write, history, notify after commit. Lock order everywhere:
 * order → variants (id asc) → cart lines → promo code (→ Slice 10 payment row).
 */
class StoreOrderLifecycle
{
    public const CANCEL_STOCK = 'stock_unavailable';

    public function __construct(
        private readonly ActivityRecorder $activity,
        private readonly AuditLogger $audit,
        private readonly Notifier $notifier,
        private readonly DeadlockRetry $retry,
    ) {}

    /**
     * Confirms an order paid by `$paymentId` (called by the Slice 10 handler's `fulfil`). Idempotent on the payment:
     * a re-run returns the order it already confirmed. Not payable any more → 409 INVALID_STATE (Slice 10 refunds).
     * Stock gone → the order is cancelled and committed, then 422 OUT_OF_STOCK (Slice 10 refunds) (D212/Q114).
     */
    public function confirmPaid(int $orderId, int $paymentId): StoreOrder
    {
        $outcome = $this->retry->run(fn () => DB::transaction(function () use ($orderId, $paymentId) {
            $order = $this->lockOrder($orderId);

            if ($order->payment_id === $paymentId && $order->status->isPlaced()) {
                return $order; // already confirmed by this payment
            }
            if ($order->status !== StoreOrderStatus::PendingPayment) {
                return 'not_payable';
            }

            $items = $order->items()->get();
            $variants = $this->lockVariants($items->pluck('product_variant_id'));

            $short = $items->first(function ($item) use ($variants) {
                $variant = $variants->get($item->product_variant_id);

                return $variant === null || ! $variant->isAvailable() || $variant->stock < $item->quantity;
            });

            if ($short !== null) {
                $this->move($order, StoreOrderStatus::Cancelled, null, 'order_cancelled', ['reason' => self::CANCEL_STOCK, 'payment_id' => $paymentId], [
                    'cancelled_at' => now(), 'cancel_reason' => self::CANCEL_STOCK,
                ]);
                $this->setPromoUse($order, PromoCodeUsageStatus::Released);

                return 'out_of_stock';
            }

            foreach ($items as $item) {
                // S18 (D279): every deduction is a stock movement on the locked variant row.
                $variant = $variants->get($item->product_variant_id);
                $before = $variant->stock;
                ProductVariant::query()->whereKey($variant->id)->decrement('stock', $item->quantity);
                $variant->refresh();
                app(StockAdjustments::class)->record($variant, $before, StockMovementSource::Order, null, null, null, $order->id);
            }

            // Her cart lines for what she bought are done (cart lines before the promo row: lock order).
            CartItem::query()
                ->whereHas('cart', fn ($cart) => $cart->where('user_id', $order->user_id))
                ->whereIn('product_variant_id', $items->pluck('product_variant_id'))
                ->delete();

            $this->setPromoUse($order, PromoCodeUsageStatus::Used);

            return $this->move($order, StoreOrderStatus::Confirmed, null, 'order_confirmed', ['payment_id' => $paymentId], [
                'payment_id' => $paymentId, 'confirmed_at' => now(),
            ]);
        }), 'store_order.confirm', ['store_order_id' => $orderId, 'payment_id' => $paymentId]);

        return match ($outcome) {
            'not_payable' => throw new ApiException(ErrorCode::InvalidState),
            'out_of_stock' => throw new ApiException(ErrorCode::OutOfStock),
            default => $outcome,
        };
    }

    /**
     * An unpaid order whose attempt is over (expiry command) or that she replaced with a newer checkout (D212/Q115).
     * Must run inside a transaction that already holds the order lock, or on its own.
     */
    public function expire(StoreOrder $order): void
    {
        DB::transaction(function () use ($order) {
            $order = $this->lockOrder($order->id);
            if ($order->status !== StoreOrderStatus::PendingPayment) {
                return;
            }
            $this->move($order, StoreOrderStatus::Expired, null, 'order_expired', [], ['expired_at' => now()]);
            $this->setPromoUse($order, PromoCodeUsageStatus::Released);
        });
    }

    /** Staff: confirmed → preparing. */
    public function prepare(StoreOrder $order, User $staff, ?string $note): StoreOrder
    {
        return $this->retry->run(fn () => DB::transaction(function () use ($order, $staff, $note) {
            $order = $this->lockOrder($order->id);
            if ($order->status === StoreOrderStatus::Preparing) {
                return $order;
            }
            $this->ensure($order, StoreOrderStatus::Preparing);
            $this->move($order, StoreOrderStatus::Preparing, $staff, 'order_preparing', $note ? ['note' => $note] : []);
            $this->audit->log('store_order_preparing', $staff, $order, $note);

            return $order;
        }), 'store_order.prepare', ['store_order_id' => $order->id]);
    }

    /**
     * Staff cancel (D211, D212): confirmed / preparing, no live shipment, reason required. Stock is restored; the promo
     * use stays used; the refund is a separate Slice 10 staff action (OD3).
     */
    public function cancelByStaff(StoreOrder $order, User $staff, string $reason): StoreOrder
    {
        return $this->retry->run(fn () => DB::transaction(function () use ($order, $staff, $reason) {
            $order = $this->lockOrder($order->id);
            if ($order->status === StoreOrderStatus::Cancelled) {
                return $order;
            }
            if (! $order->status->isCancellableByStaff() || $order->shipments()->whereIn('status', ['pending', 'in_transit'])->exists()) {
                throw new ApiException(ErrorCode::InvalidState);
            }

            $items = $order->items()->get();
            $locked = $this->lockVariants($items->pluck('product_variant_id'));
            foreach ($items as $item) {
                // S18 (D279): every restoration is a stock movement on the locked variant row.
                $variant = $locked->get($item->product_variant_id);
                $before = $variant->stock;
                ProductVariant::query()->whereKey($variant->id)->increment('stock', $item->quantity);
                $variant->refresh();
                app(StockAdjustments::class)->record($variant, $before, StockMovementSource::OrderCancelled, $staff, null, null, $order->id);
            }

            $from = $order->status;
            $this->move($order, StoreOrderStatus::Cancelled, $staff, 'order_cancelled', ['reason' => 'staff'], [
                'cancelled_at' => now(), 'cancel_reason' => $reason, 'cancelled_by' => $staff->id,
            ]);
            $this->audit->log('store_order_cancelled', $staff, $order, $reason, ['status' => $from->value], ['status' => StoreOrderStatus::Cancelled->value]);

            return $order;
        }), 'store_order.cancel', ['store_order_id' => $order->id]);
    }

    /** A shipment went in transit: confirmed / preparing → shipped. Call inside the shipment transaction (order locked). */
    public function markShipped(StoreOrder $order, ?User $actor): void
    {
        if ($order->status === StoreOrderStatus::Shipped) {
            return;
        }
        $this->ensure($order, StoreOrderStatus::Shipped);
        $this->move($order, StoreOrderStatus::Shipped, $actor, 'order_shipped');
    }

    /** Its shipment was delivered: shipped → delivered. Call with the order locked. */
    public function markDelivered(StoreOrder $order, ?User $actor): void
    {
        if ($order->status === StoreOrderStatus::Delivered) {
            return;
        }
        $this->ensure($order, StoreOrderStatus::Delivered);
        $this->move($order, StoreOrderStatus::Delivered, $actor, 'order_delivered', [], ['delivered_at' => now()]);
    }

    /** Its shipment failed: shipped → preparing, staff ship again or cancel (D212/Q118). Call with the order locked. */
    public function deliveryFailed(StoreOrder $order, ?User $actor, ?string $reason): void
    {
        if ($order->status !== StoreOrderStatus::Shipped) {
            return;
        }
        $this->move($order, StoreOrderStatus::Preparing, $actor, 'order_delivery_failed', $reason ? ['reason' => $reason] : []);
    }

    /** History of a settled Slice 10 refund (no status change: a refund is not a cancellation). */
    public function recordRefund(StoreOrder $order, string $amount, bool $full): void
    {
        $this->activity->record($order, $full ? 'order_refunded' : 'order_partially_refunded', meta: ['amount' => $amount]);
    }

    public function lockOrder(int $id): StoreOrder
    {
        return StoreOrder::query()->whereKey($id)->lockForUpdate()->firstOrFail();
    }

    /**
     * @param  Collection<int, int>  $ids
     * @return Collection<int, ProductVariant> keyed by id
     */
    private function lockVariants(Collection $ids): Collection
    {
        return ProductVariant::query()->whereKey($ids->unique()->sort()->values())->orderBy('id')->lockForUpdate()
            ->get()->load('product.category')->keyBy('id');
    }

    private function setPromoUse(StoreOrder $order, PromoCodeUsageStatus $status): void
    {
        $usage = PromoCodeUsage::query()->where('store_order_id', $order->id)->first();
        if ($usage === null || $usage->status === $status) {
            return;
        }
        PromoCode::query()->whereKey($usage->promo_code_id)->lockForUpdate()->first(['id']);
        $usage->update(['status' => $status]);
    }

    private function ensure(StoreOrder $order, StoreOrderStatus $to): void
    {
        if (! $order->status->canMoveTo($to)) {
            throw new ApiException(ErrorCode::InvalidState);
        }
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $extra  columns written with the status
     */
    private function move(StoreOrder $order, StoreOrderStatus $to, ?User $actor, string $event, array $meta = [], array $extra = []): StoreOrder
    {
        $from = $order->status;
        $order->forceFill(['status' => $to, ...$extra])->save();
        // Staff notes are internal; the client-visible status event never carries them.
        $history = $this->activity->record($order, $event, $from, $to, $actor, ActivityVisibility::All, Arr::except($meta, ['note']));
        if (isset($meta['note'])) {
            $this->activity->record($order, 'order_note', actor: $actor, visibility: ActivityVisibility::Internal, meta: ['note' => $meta['note']]);
        }

        $notify = match ($to) {
            StoreOrderStatus::Confirmed => NotificationEvent::StoreOrderConfirmed,
            StoreOrderStatus::Shipped => NotificationEvent::StoreOrderShipped,
            StoreOrderStatus::Delivered => NotificationEvent::StoreOrderDelivered,
            StoreOrderStatus::Cancelled => NotificationEvent::StoreOrderCancelled,
            default => null,
        };
        if ($notify !== null) {
            $this->notifier->send($order->user_id, $notify, ['reference' => $order->reference], NotificationTargetType::StoreOrder,
                $order->id, "store_order:{$order->id}:{$to->value}:{$history->id}", $actor); // re-shipping after a failure notifies again
        }

        return $order;
    }
}
