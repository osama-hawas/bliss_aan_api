<?php

namespace App\Services\Store\Returns;

use App\Enums\ActivityVisibility;
use App\Enums\ErrorCode;
use App\Enums\NotificationEvent;
use App\Enums\NotificationTargetType;
use App\Enums\RefundKind;
use App\Enums\RefundStatus;
use App\Enums\StockMovementSource;
use App\Enums\StoreReturnStatus;
use App\Exceptions\ApiException;
use App\Models\Payment;
use App\Models\ProductVariant;
use App\Models\Refund;
use App\Models\StoreOrder;
use App\Models\StoreReturn;
use App\Models\User;
use App\Services\Audit\ActivityRecorder;
use App\Services\Audit\AuditLogger;
use App\Services\Availability\DeadlockRetry;
use App\Services\Notifications\Notifier;
use App\Services\Payments\PaymentRefunds;
use App\Services\Store\Admin\StockAdjustments;
use App\Services\Store\StoreMoney;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Staff side of a store return (S23, OD37; `slice-23-contract.md` §6): approve, reject (reason), mark received (restock
 * + refund), retry the refund, and settle it when Slice 10 says the refund is done.
 *
 * Lock order everywhere: **return → order → variants (id asc) → (Slice 10) payment**. The refund is two steps, like
 * every Slice 10 refund: the return is moved to `received` and committed (return → order → variants), then
 * `PaymentRefunds::refund()` reserves the amount (order → payment) and calls the gateway outside any transaction. When
 * the refund settles, `settle()` locks the return alone and moves it to `refunded` (E59). The refund's idempotency key
 * `store_return:{id}:{attempt}` links it to the return, so a webhook or the reconcile command settles it too.
 */
class StoreReturnProcessing
{
    public const REFUND_KEY_PREFIX = 'store_return:';

    public function __construct(
        private readonly ActivityRecorder $activity,
        private readonly AuditLogger $audit,
        private readonly Notifier $notifier,
        private readonly DeadlockRetry $retry,
        private readonly StockAdjustments $stock,
        private readonly PaymentRefunds $refunds,
    ) {}

    public function approve(int $returnId, User $staff, ?string $note): StoreReturn
    {
        return $this->retry->run(fn () => DB::transaction(function () use ($returnId, $staff, $note) {
            [$return, $order] = $this->lock($returnId);
            if ($return->status === StoreReturnStatus::Approved) {
                return $return;
            }
            $this->ensure($return, StoreReturnStatus::Approved);
            $this->move($return, $order, StoreReturnStatus::Approved, $staff, 'return_approved', $note,
                ['approved_at' => now(), 'approved_by' => $staff->id]);
            $this->audit->log('store_return_approved', $staff, $return, $note, ['status' => 'requested'], ['status' => 'approved']);
            $this->notify($return, NotificationEvent::StoreReturnApproved, $staff);

            return $return;
        }), 'store_return.approve', ['store_return_id' => $returnId]);
    }

    public function reject(int $returnId, User $staff, string $reason): StoreReturn
    {
        return $this->retry->run(fn () => DB::transaction(function () use ($returnId, $staff, $reason) {
            [$return, $order] = $this->lock($returnId);
            if ($return->status === StoreReturnStatus::Rejected) {
                return $return;
            }
            $this->ensure($return, StoreReturnStatus::Rejected);
            $this->move($return, $order, StoreReturnStatus::Rejected, $staff, 'return_rejected', null,
                ['rejected_at' => now(), 'rejected_by' => $staff->id, 'reject_reason' => $reason], ['reason' => $reason]);
            $this->audit->log('store_return_rejected', $staff, $return, $reason, ['status' => 'requested'], ['status' => 'rejected']);
            $this->notify($return, NotificationEvent::StoreReturnRejected, $staff);

            return $return;
        }), 'store_return.reject', ['store_return_id' => $returnId]);
    }

    /**
     * approved → received: the items are back. With `$restock`, each line goes back on the shelf as an S18 stock
     * movement (`store_return`). Then the refund runs (D345). A refund that cannot start or fails leaves the return
     * `received` with `refund_error`; staff retry it with `refund()`. Repeating it on a received return only retries a
     * refund that is not running or done.
     */
    public function receive(int $returnId, User $staff, bool $restock, ?string $note): StoreReturn
    {
        $this->retry->run(fn () => DB::transaction(function () use ($returnId, $staff, $restock, $note) {
            [$return, $order] = $this->lock($returnId);
            if (in_array($return->status, [StoreReturnStatus::Received, StoreReturnStatus::Refunded], true)) {
                return;
            }
            $this->ensure($return, StoreReturnStatus::Received);

            $items = $return->items()->get();
            if ($restock) {
                $variants = ProductVariant::query()->whereKey($items->pluck('product_variant_id')->unique()->sort()->values())
                    ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                foreach ($items->sortBy('product_variant_id') as $item) {
                    $variant = $variants->get($item->product_variant_id);
                    $before = $variant->stock;
                    ProductVariant::query()->whereKey($variant->id)->increment('stock', $item->quantity);
                    $variant->refresh();
                    $this->stock->record($variant, $before, StockMovementSource::StoreReturn, $staff, null, $return->reference, $order->id);
                }
            }

            $this->move($return, $order, StoreReturnStatus::Received, $staff, 'return_received', $note,
                ['received_at' => now(), 'received_by' => $staff->id, 'restocked' => $restock], ['restocked' => $restock]);
            $this->audit->log('store_return_received', $staff, $return, $note, ['status' => 'approved'],
                ['status' => 'received', 'restocked' => $restock, 'refund_amount' => Money::normalize($return->refund_amount)]);
            $this->notify($return, NotificationEvent::StoreReturnReceived, $staff);
        }), 'store_return.receive', ['store_return_id' => $returnId]);

        return $this->refund($returnId, $staff);
    }

    /**
     * Starts (or retries) the refund of a `received` return. Nothing happens while a refund of it is in progress or
     * once it is refunded. The amount is capped at what the payment still has to give back (staff may have refunded
     * part of the order by hand on the payment page).
     */
    public function refund(int $returnId, User $staff): StoreReturn
    {
        // Attempt n = 1 + the return's failed refunds, so two parallel calls build the same key and Slice 10 replays it.
        $attempt = $this->retry->run(fn () => DB::transaction(function () use ($returnId) {
            $return = $this->lockReturn($returnId);
            if ($return->status !== StoreReturnStatus::Received) {
                return null;
            }
            $refunds = $this->refundsOf($return);
            if ($refunds->contains(fn (Refund $r) => $r->status !== RefundStatus::Failed)) {
                return null; // in progress or refunded: settle() moves the return
            }
            $return->forceFill(['refund_attempts' => $refunds->count() + 1, 'refund_error' => null])->save();

            return $return;
        }), 'store_return.refund_attempt', ['store_return_id' => $returnId]);

        if ($attempt === null) {
            return $this->fresh($returnId);
        }

        $payment = Payment::query()->find($attempt->order->payment_id);
        $key = self::REFUND_KEY_PREFIX.$attempt->id.':'.$attempt->refund_attempts;
        if ($payment !== null && ($existing = Refund::query()->where('payment_id', $payment->id)->where('idempotency_key', $key)->first())) {
            $this->settle($existing);

            return $this->fresh($returnId);
        }
        $amount = min(StoreMoney::cents((string) $attempt->refund_amount), $payment?->refundableCents() ?? 0);
        if ($payment === null || $amount <= 0) {
            return $this->fail($attempt, 'NOTHING_REFUNDABLE');
        }

        try {
            [$refund] = $this->refunds->refund($payment, StoreMoney::amount($amount), RefundKind::StoreReturn, $staff,
                __('store.returns.refund_reason', ['reference' => $attempt->reference]), $key);
        } catch (ApiException $e) {
            if ($e->errorCode === ErrorCode::DuplicateRequest) {
                return $this->fresh($returnId); // a parallel call started this attempt first
            }
            Log::info('Store return refund refused.', ['store_return_id' => $attempt->id, 'code' => $e->errorCode->value]);

            return $this->fail($attempt, $e->errorCode->value);
        }

        $this->settle($refund->fresh());

        return $this->fresh($returnId);
    }

    /** @return Collection<int, Refund> the Slice 10 refunds started for this return */
    public function refundsOf(StoreReturn $return): Collection
    {
        $paymentId = $return->order()->value('payment_id');
        if ($paymentId === null) {
            return collect();
        }

        return Refund::query()->where('payment_id', $paymentId)
            ->where('idempotency_key', 'like', self::REFUND_KEY_PREFIX.$return->id.':%')
            ->orderBy('id')->get();
    }

    /**
     * A Slice 10 refund of a return settled (called after commit by the `RefundSettled` listener and right after the
     * gateway call). Idempotent: received → refunded once (E59); a failed refund records its code.
     */
    public function settle(Refund $refund): void
    {
        $returnId = self::returnIdFromKey((string) $refund->idempotency_key);
        if ($returnId === null) {
            return;
        }

        $this->retry->run(fn () => DB::transaction(function () use ($returnId, $refund) {
            $return = StoreReturn::query()->whereKey($returnId)->lockForUpdate()->first();
            $refund = Refund::query()->find($refund->id);
            if ($return === null || $refund === null || $return->status !== StoreReturnStatus::Received) {
                return;
            }
            if ($refund->status === RefundStatus::Failed) {
                if ($return->refund_id === null) {
                    $return->forceFill(['refund_error' => $refund->failure_code ?? 'gateway_error'])->save();
                }

                return;
            }
            if ($refund->status !== RefundStatus::Refunded) {
                return;
            }

            $return->forceFill(['status' => StoreReturnStatus::Refunded, 'refund_id' => $refund->id, 'refunded_at' => now(), 'refund_error' => null])->save();
            $this->activity->record($return, 'return_refunded', StoreReturnStatus::Received, StoreReturnStatus::Refunded, null,
                ActivityVisibility::All, ['refund_id' => $refund->id, 'amount' => Money::normalize($refund->amount)]);
            $this->notifier->send($return->user_id, NotificationEvent::StoreReturnRefunded,
                ['reference' => $return->reference, 'amount' => Money::normalize($refund->amount).' '.config('app.currency')],
                NotificationTargetType::StoreReturn, $return->id, "store_return:{$return->id}:refunded");
        }), 'store_return.settle', ['store_return_id' => $returnId, 'refund_id' => $refund->id]);
    }

    public static function returnIdFromKey(string $key): ?int
    {
        if (! str_starts_with($key, self::REFUND_KEY_PREFIX)) {
            return null;
        }
        $id = explode(':', substr($key, strlen(self::REFUND_KEY_PREFIX)))[0] ?? '';

        return ctype_digit($id) ? (int) $id : null;
    }

    private function fail(StoreReturn $return, string $code): StoreReturn
    {
        StoreReturn::query()->whereKey($return->id)->where('status', StoreReturnStatus::Received->value)->update(['refund_error' => $code]);

        return $this->fresh($return->id);
    }

    /** @return array{0: StoreReturn, 1: StoreOrder} the return then its order, both locked (lock order). */
    private function lock(int $returnId): array
    {
        $return = $this->lockReturn($returnId);
        $order = StoreOrder::query()->whereKey($return->store_order_id)->lockForUpdate()->firstOrFail();

        return [$return, $order];
    }

    private function lockReturn(int $returnId): StoreReturn
    {
        return StoreReturn::query()->whereKey($returnId)->lockForUpdate()->first()
            ?? throw new ApiException(ErrorCode::NotFound);
    }

    private function fresh(int $returnId): StoreReturn
    {
        return StoreReturn::query()->findOrFail($returnId);
    }

    private function ensure(StoreReturn $return, StoreReturnStatus $to): void
    {
        if (! $return->status->canMoveTo($to)) {
            throw new ApiException(ErrorCode::InvalidState);
        }
    }

    /**
     * @param  array<string, mixed>  $columns
     * @param  array<string, mixed>  $meta
     */
    private function move(StoreReturn $return, StoreOrder $order, StoreReturnStatus $to, User $staff, string $event, ?string $note, array $columns, array $meta = []): void
    {
        $from = $return->status;
        $return->forceFill(['status' => $to, ...$columns])->save();
        $this->activity->record($return, $event, $from, $to, $staff, ActivityVisibility::All, $meta);
        if ($note !== null && $note !== '') {
            $this->activity->record($return, 'return_note', actor: $staff, visibility: ActivityVisibility::Internal, meta: ['note' => $note]);
        }
        $this->activity->record($order, 'order_'.$event, actor: $staff, visibility: ActivityVisibility::Internal,
            meta: ['store_return_id' => $return->id, 'reference' => $return->reference]);
    }

    private function notify(StoreReturn $return, NotificationEvent $event, User $staff): void
    {
        $this->notifier->send($return->user_id, $event, ['reference' => $return->reference], NotificationTargetType::StoreReturn,
            $return->id, "store_return:{$return->id}:{$return->status->value}", $staff);
    }
}
