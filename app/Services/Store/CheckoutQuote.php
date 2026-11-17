<?php

namespace App\Services\Store;

use App\Models\Address;
use App\Models\CartItem;
use App\Models\PromoCode;
use Illuminate\Support\Collection;

/**
 * The priced checkout of her cart at one moment (`slice-9b-contract.md` §6.1). All money in integer cents.
 */
final readonly class CheckoutQuote
{
    /**
     * @param  Collection<int, CartItem>  $lines  her cart lines with variant.product loaded
     * @param  list<array{variant_id: int, name: string, previous_unit_price: string, unit_price: string}>  $priceChanges
     */
    public function __construct(
        public Collection $lines,
        public array $priceChanges,
        public int $subtotal,
        public int $discount,
        public ?PromoCode $promo,
        public int $shippingFee,
        public ?string $vatPercent,
        public ?int $vat,
        public int $total,
        public ?Address $address,
    ) {}

    public function totalAmount(): string
    {
        return StoreMoney::amount($this->total);
    }
}
