<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One variant in a cart. Prices are read live; `added_unit_price` is the price when the quantity was last set, only to
 * tell her a price changed since (D210). Snapshots are taken by the order at checkout.
 */
class CartItem extends Model
{
    protected $fillable = ['cart_id', 'product_variant_id', 'quantity', 'added_unit_price'];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'added_unit_price' => 'decimal:2'];
    }

    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
