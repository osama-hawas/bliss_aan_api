<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The provider's one payout account (D216). The IBAN is encrypted at rest and only ever shown masked to her.
 */
class PayoutAccount extends Model
{
    protected $fillable = ['provider_id', 'holder_name', 'iban', 'iban_last4', 'bank_code', 'bank_name_key', 'verified_at'];

    protected $hidden = ['iban'];

    protected function casts(): array
    {
        return [
            'iban' => 'encrypted',
            'verified_at' => 'datetime',
        ];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }
}
