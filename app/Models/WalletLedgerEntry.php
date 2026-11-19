<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Client wallet money (owner decision 2026-11-19): top-ups, booking payments and refunds (`WalletLedger`). Only
 * `available` entries count towards the balance.
 */
class WalletLedgerEntry extends Model
{
    public const STATUS_AVAILABLE = 'available';

    protected $fillable = ['wallet_id', 'type', 'amount', 'status', 'source_type', 'source_id', 'meta', 'idempotency_key'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'meta' => 'array'];
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }
}
