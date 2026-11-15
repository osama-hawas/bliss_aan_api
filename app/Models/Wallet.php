<?php

namespace App\Models;

use App\Enums\UserType;
use App\Enums\WalletStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A client's or provider's informational wallet (D106). There is no balance column: the balance is derived from
 * `available` ledger entries (WalletService::balance). It is never spendable and never touches bookings.
 */
class Wallet extends Model
{
    protected $fillable = ['user_id', 'owner_type', 'active_owner_id', 'label', 'currency', 'status', 'closed_at'];

    protected function casts(): array
    {
        return [
            'owner_type' => UserType::class,
            'status' => WalletStatus::class,
            'closed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(WalletLedgerEntry::class);
    }
}
