<?php

namespace App\Models;

use App\Enums\PromoCodeType;
use App\Enums\PromoCodeUsageStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A store promo code (D200, D213): percent or fixed, on the products subtotal only, one per order. Never deleted;
 * staff stop it (`is_active`).
 *
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 */
class PromoCode extends Model
{
    protected $fillable = [
        'code', 'type', 'value', 'min_amount', 'usage_limit', 'per_user_limit', 'starts_at', 'ends_at', 'is_active',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'type' => PromoCodeType::class,
            'value' => 'decimal:2',
            'min_amount' => 'decimal:2',
            'usage_limit' => 'integer',
            'per_user_limit' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    public function usages(): HasMany
    {
        return $this->hasMany(PromoCodeUsage::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Held + used uses (they count against the limits). */
    public function scopeWithUsedCount(Builder $query): Builder
    {
        return $query->withCount(['usages as used_count' => fn (Builder $usage) => $usage->whereIn('status', PromoCodeUsageStatus::counting())]);
    }

    /** active | inactive (stopped) | expired (ended) | scheduled (not started yet). */
    public function state(): string
    {
        return match (true) {
            ! $this->is_active => 'inactive',
            $this->ends_at !== null && $this->ends_at->isPast() => 'expired',
            $this->starts_at !== null && $this->starts_at->isFuture() => 'scheduled',
            default => 'active',
        };
    }

    public static function normalize(string $code): string
    {
        return mb_strtoupper(trim($code));
    }
}
