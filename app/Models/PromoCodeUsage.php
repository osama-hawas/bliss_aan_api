<?php

namespace App\Models;

use App\Enums\PromoCodeUsageStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One order's use of a promo code (D212/Q116): held at checkout, used when paid, released when the attempt expired or
 * the order was cancelled for stock.
 */
class PromoCodeUsage extends Model
{
    protected $fillable = ['promo_code_id', 'user_id', 'store_order_id', 'status'];

    protected function casts(): array
    {
        return ['status' => PromoCodeUsageStatus::class];
    }

    public function promoCode(): BelongsTo
    {
        return $this->belongsTo(PromoCode::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(StoreOrder::class, 'store_order_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
