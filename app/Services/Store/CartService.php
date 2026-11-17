<?php

namespace App\Services\Store;

use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Settings\AppSettings;
use Illuminate\Validation\ValidationException;

/**
 * Server-side cart CRUD (Slice 9, `slice-9-contract.md` §5). Her own cart only; created on the first item.
 * A quantity is checked against the variant's current stock (and `store.max_qty_per_line`, D208) when it is set.
 * Nothing is reserved (D207); lines that later become unavailable or exceed stock are kept and flagged, and checkout
 * is refused until she fixes them (D205).
 */
class CartService
{
    public function __construct(private readonly AppSettings $settings) {}

    /**
     * Her cart with its lines, or null when she has none yet.
     */
    public function find(User $user): ?Cart
    {
        return Cart::query()->where('user_id', $user->id)
            ->with(['items' => fn ($items) => $items->orderBy('id'), 'items.variant.product.image', 'items.variant.product.category'])
            ->first();
    }

    /**
     * Sets the quantity of one variant (adds the line when missing). Idempotent: the same call gives the same cart.
     */
    public function setQuantity(User $user, int $variantId, int $quantity): ?Cart
    {
        $variant = ProductVariant::query()->available()->find($variantId)
            ?? throw new ApiException(ErrorCode::NotFound);

        // D208: an optional per-line maximum; a limit, not a stock number, so it may be shown.
        $max = $this->settings->get('store.max_qty_per_line');
        if ($max !== null && $quantity > (int) $max) {
            throw ValidationException::withMessages(['quantity' => __('store.max_quantity', ['max' => (int) $max])]);
        }

        if ($quantity > $variant->stock) {
            throw new ApiException(ErrorCode::OutOfStock, __('store.out_of_stock'), ['quantity' => [__('store.out_of_stock')]]);
        }

        $cart = $this->cartOf($user);

        // One statement on unique(cart_id, product_variant_id): concurrent calls cannot create two lines.
        CartItem::query()->upsert(
            // added_unit_price: the price she sees now, for the "price changed" notice (D210).
            [['cart_id' => $cart->id, 'product_variant_id' => $variant->id, 'quantity' => $quantity, 'added_unit_price' => $variant->price, 'created_at' => now(), 'updated_at' => now()]],
            ['cart_id', 'product_variant_id'],
            ['quantity', 'added_unit_price', 'updated_at'],
        );

        return $this->find($user);
    }

    /**
     * Removes one variant's line. Removing a line that is not there is not an error.
     */
    public function remove(User $user, int $variantId): ?Cart
    {
        CartItem::query()
            ->whereHas('cart', fn ($cart) => $cart->where('user_id', $user->id))
            ->where('product_variant_id', $variantId)
            ->delete();

        return $this->find($user);
    }

    /**
     * Empties her cart (the cart row itself is kept).
     */
    public function clear(User $user): ?Cart
    {
        CartItem::query()->whereHas('cart', fn ($cart) => $cart->where('user_id', $user->id))->delete();

        return $this->find($user);
    }

    public function cartOf(User $user): Cart
    {
        // insertOrIgnore on unique(user_id): two first adds at once still give one cart.
        Cart::query()->insertOrIgnore(['user_id' => $user->id, 'created_at' => now(), 'updated_at' => now()]);

        return Cart::query()->where('user_id', $user->id)->firstOrFail();
    }
}
