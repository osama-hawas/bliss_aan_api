<?php

namespace App\Services\Store;

use App\Enums\ErrorCode;
use App\Enums\PayableType;
use App\Enums\PaymentPurpose;
use App\Enums\StoreOrderStatus;
use App\Exceptions\ApiException;
use App\Models\Payment;
use App\Models\ProductVariant;
use App\Models\Refund;
use App\Models\StoreOrder;
use App\Models\User;
use App\Services\Payments\PayableHandler;
use App\Services\Payments\PaymentQuote;
use Illuminate\Database\Eloquent\Model;

/**
 * The store order as a Slice 10 payable (`slice-10-contract.md` §4.3, D190, D214; `slice-9b-contract.md` §7.2).
 * Registered in `PayableRegistry` for `PayableType::StoreOrder`.
 */
class StoreOrderPaymentHandler implements PayableHandler
{
    public function __construct(private readonly StoreOrderLifecycle $lifecycle) {}

    public function type(): PayableType
    {
        return PayableType::StoreOrder;
    }

    /** Her own order (any status; `quote` refuses what can't be paid), or 404. */
    public function findForPayer(User $payer, int $id): Model
    {
        return StoreOrder::query()->where('user_id', $payer->id)->find($id)
            ?? throw new ApiException(ErrorCode::NotFound);
    }

    /**
     * No locks. Only an unpaid order; every item still purchasable with enough stock right now (nothing charged
     * otherwise). The amount is the order's snapshot total: price changes after checkout don't touch it.
     */
    public function quote(User $payer, Model $payable): PaymentQuote
    {
        /** @var StoreOrder $payable */
        if ($payable->status !== StoreOrderStatus::PendingPayment) {
            throw new ApiException(ErrorCode::InvalidState);
        }

        $items = $payable->items()->get();
        $variants = ProductVariant::query()->whereKey($items->pluck('product_variant_id'))->with('product.category')->get()->keyBy('id');
        $short = $items->filter(function ($item) use ($variants) {
            $variant = $variants->get($item->product_variant_id);

            return $variant === null || ! $variant->isAvailable() || $variant->stock < $item->quantity;
        });
        if ($short->isNotEmpty()) {
            throw new ApiException(ErrorCode::CartHasIssues, null, ['items' => $short->map(fn ($item) => (string) $item->product_variant_id)->values()->all()]);
        }

        return new PaymentQuote(
            amount: $payable->total,
            purpose: PaymentPurpose::Store,
            description: $payable->reference,
            metadata: ['store_order_id' => $payable->id, 'reference' => $payable->reference],
        );
    }

    /**
     * Confirms the order and deducts stock (D207). Idempotent on the payment id. Throws ApiException when the order
     * can't be confirmed (expired / cancelled / stock gone) → Slice 10 refunds in full automatically (D212/Q114).
     */
    public function fulfil(Payment $payment): ?Model
    {
        return $this->lifecycle->confirmPaid((int) $payment->payable_id, (int) $payment->id);
    }

    /** Same first lock as a staff cancel, so a cancel and a refund never deadlock. */
    public function lockForRefund(Payment $payment): void
    {
        $this->lifecycle->lockOrder((int) $payment->payable_id);
    }

    /** History only: a refund is not a cancellation (staff cancel separately). */
    public function onRefunded(Payment $payment, Refund $refund): void
    {
        $status = $refund->status instanceof \BackedEnum ? $refund->status->value : $refund->status;
        $order = StoreOrder::query()->find($payment->payable_id);
        if ($order === null || $status !== 'refunded') {
            return;
        }
        $full = StoreMoney::cents((string) $payment->refunded_amount) >= StoreMoney::cents((string) $payment->amount);
        $this->lifecycle->recordRefund($order, (string) $refund->amount, $full);
    }
}
