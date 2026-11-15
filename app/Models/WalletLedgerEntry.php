<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Future extension point (D106): nothing creates entries in the current scope. Only `available` entries count.
 */
class WalletLedgerEntry extends Model
{
    public const STATUS_AVAILABLE = 'available';

    protected $fillable = ['wallet_id', 'type', 'amount', 'status', 'source_type', 'source_id', 'meta'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'meta' => 'array'];
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }
}
