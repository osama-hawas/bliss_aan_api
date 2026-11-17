<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A card saved as a gateway token (D188, D193): brand, last four and expiry only, never a card number.
 */
class SavedPaymentMethod extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'user_id', 'gateway', 'gateway_token', 'token_hash', 'active_token_hash', 'brand', 'last4', 'exp_month',
        'exp_year', 'is_default',
    ];

    protected $hidden = ['gateway_token', 'token_hash', 'active_token_hash'];

    protected function casts(): array
    {
        return [
            'gateway_token' => 'encrypted',
            'is_default' => 'boolean',
            'exp_month' => 'integer',
            'exp_year' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isExpired(): bool
    {
        $now = now('Asia/Riyadh');

        return $this->exp_year < $now->year || ($this->exp_year === $now->year && $this->exp_month < $now->month);
    }

    /**
     * @return array{brand: string, last4: string, exp_month: int, exp_year: int}
     */
    public function snapshot(): array
    {
        return ['brand' => $this->brand, 'last4' => $this->last4, 'exp_month' => $this->exp_month, 'exp_year' => $this->exp_year];
    }
}
