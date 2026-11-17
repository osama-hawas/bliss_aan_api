<?php

namespace App\Services\Store;

use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\ProductVariant;
use App\Models\StoreOrder;
use App\Models\StoreOrderItem;
use App\Models\User;
use App\Services\Settings\AppSettings;
use Illuminate\Support\Facades\DB;

/**
 * Reorder (D211): copies a placed order's lines into her cart at current stock and prices. Each line becomes
 * min(existing + ordered, stock, max per line); unpurchasable lines are skipped. Capped, so repeating is harmless.
 */
class ReorderService
{
    public function __construct(
        private readonly CartService $carts,
        private readonly AppSettings $settings,
    ) {}

    /**
     * @return array{0: Cart|null, 1: list<array{variant_id: int, name: string, reason: string, reason_label: string}>}
     */
    public function reorder(User $client, StoreOrder $order): array
    {
        if (! $order->status->isPlaced()) {
            throw new ApiException(ErrorCode::InvalidState);
        }

        $max = $this->settings->get('store.max_qty_per_line');
        $skipped = [];

        DB::transaction(function () use ($client, $order, $max, &$skipped) {
            $cart = $this->carts->cartOf($client);
            $existing = CartItem::query()->where('cart_id', $cart->id)->lockForUpdate()->get()->keyBy('product_variant_id');

            $order->items()->get()->each(function (StoreOrderItem $item) use ($cart, $existing, $max, &$skipped) {
                $variant = ProductVariant::query()->available()->with('product')->find($item->product_variant_id);
                if ($variant === null) {
                    $skipped[] = $this->skip($item, 'unavailable');

                    return;
                }
                if ($variant->stock < 1) {
                    $skipped[] = $this->skip($item, 'out_of_stock');

                    return;
                }

                $wanted = ($existing->get($variant->id)?->quantity ?? 0) + $item->quantity;
                $quantity = min($wanted, $variant->stock, $max === null ? PHP_INT_MAX : (int) $max);
                if ($quantity < $wanted) {
                    $skipped[] = $this->skip($item, 'reduced');
                }

                CartItem::query()->upsert(
                    [['cart_id' => $cart->id, 'product_variant_id' => $variant->id, 'quantity' => $quantity, 'added_unit_price' => $variant->price, 'created_at' => now(), 'updated_at' => now()]],
                    ['cart_id', 'product_variant_id'],
                    ['quantity', 'added_unit_price', 'updated_at'],
                );
            });
        });

        return [$this->carts->find($client), $skipped];
    }

    /** @return array{variant_id: int, name: string, reason: string, reason_label: string} */
    private function skip(StoreOrderItem $item, string $reason): array
    {
        return ['variant_id' => $item->product_variant_id, 'name' => $item->localizedName(), 'reason' => $reason, 'reason_label' => __('store.reorder.'.$reason)];
    }
}
