<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A payment receipt (S21, D325): issued once per succeeded payment and never rewritten. Not a tax invoice (OD8).
 */
class PaymentReceipt extends Model
{
    protected $fillable = ['payment_id', 'amount', 'currency', 'vat_percent', 'vat_amount', 'issued_by', 'issued_at',
        // S22 (D338): parties as they were at the sale.
        'payer_name', 'payer_phone', 'provider_name'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'vat_percent' => 'decimal:2',
            'vat_amount' => 'decimal:2',
            'issued_at' => 'datetime',
        ];
    }

    /** `RC-0000042`, from the row id: sequential and unique. */
    public function number(): string
    {
        return sprintf('RC-%07d', $this->id);
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }
}
