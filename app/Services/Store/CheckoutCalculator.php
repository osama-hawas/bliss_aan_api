<?php

namespace App\Services\Store;

use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Models\Address;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\User;
use App\Services\Settings\AppSettings;
use Illuminate\Support\Collection;

/**
 * Prices her cart for checkout with live prices (D210): lines → subtotal → promo (products subtotal only, D213) →
 * flat shipping fee (D201) → total; VAT is included and only shown when `tax.vat_percent` is set (D202).
 * Flagged lines refuse checkout (D205). Used by the preview (no locks) and by order creation (inside its locks).
 */
class CheckoutCalculator
{
    public function __construct(
        private readonly AppSettings $settings,
        private readonly PromoCodeCheck $promos,
    ) {}

    public function quote(User $client, ?int $addressId, ?string $promoCode, bool $lock = false): CheckoutQuote
    {
        $lines = $this->lines($client, $lock);
        if ($lines->isEmpty()) {
            throw new ApiException(ErrorCode::CartEmpty);
        }

        $flagged = $lines->filter(fn (CartItem $line) => ! $line->variant->isAvailable() || $line->quantity > $line->variant->stock);
        if ($flagged->isNotEmpty()) {
            throw new ApiException(ErrorCode::CartHasIssues, null, ['items' => $flagged->map(fn (CartItem $line) => (string) $line->product_variant_id)->values()->all()]);
        }

        $address = $addressId === null ? null : $this->address($client, $addressId);
        $shipping = StoreMoney::cents((string) $this->settings->required('store.shipping_fee'));

        $subtotal = $lines->sum(fn (CartItem $line) => StoreMoney::cents($line->variant->price) * $line->quantity);
        [$promo, $discount] = $promoCode !== null && trim($promoCode) !== ''
            ? $this->promos->evaluate($promoCode, $client, $subtotal, $lock)
            : [null, 0];

        $total = $subtotal - $discount + $shipping;
        if ($promo !== null && $total <= 0) {
            $this->promos->refuse('covers_order'); // Slice 10 quotes must be > 0 (D214)
        }

        $vatPercent = $this->settings->get('tax.vat_percent');
        $vat = null;
        if ($vatPercent !== null) {
            $p = StoreMoney::cents((string) $vatPercent);
            $vat = StoreMoney::mulDiv($total, $p, 10000 + $p);
            $vatPercent = StoreMoney::amount($p);
        }

        return new CheckoutQuote(
            lines: $lines,
            priceChanges: $this->priceChanges($lines),
            subtotal: $subtotal,
            discount: $discount,
            promo: $promo,
            shippingFee: $shipping,
            vatPercent: $vatPercent,
            vat: $vat,
            total: $total,
            address: $address,
        );
    }

    /**
     * Her own, not deleted address, or 404.
     */
    public function address(User $client, int $addressId): Address
    {
        return Address::query()->where('user_id', $client->id)->with('city')->find($addressId)
            ?? throw new ApiException(ErrorCode::NotFound);
    }

    /**
     * @return Collection<int, CartItem>
     */
    private function lines(User $client, bool $lock): Collection
    {
        $cartId = Cart::query()->where('user_id', $client->id)->value('id');
        if ($cartId === null) {
            return collect();
        }

        $query = CartItem::query()->where('cart_id', $cartId)->orderBy('id');
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->get()->load(['variant.product.category', 'variant.product.image']);
    }

    /**
     * @param  Collection<int, CartItem>  $lines
     * @return list<array{variant_id: int, name: string, previous_unit_price: string, unit_price: string}>
     */
    private function priceChanges(Collection $lines): array
    {
        return $lines
            ->filter(fn (CartItem $line) => $line->added_unit_price !== null && StoreMoney::cents($line->added_unit_price) !== StoreMoney::cents($line->variant->price))
            ->map(fn (CartItem $line) => [
                'variant_id' => $line->product_variant_id,
                'name' => $line->variant->product->name,
                'previous_unit_price' => $line->added_unit_price,
                'unit_price' => $line->variant->price,
            ])->values()->all();
    }
}
