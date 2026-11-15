<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of an offer, a snapshot (never repriced).
 */
class OfferItem extends Model
{
    protected $fillable = ['offer_id', 'description', 'quantity', 'unit_price', 'line_total', 'source_type', 'source_id', 'sort'];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'unit_price' => 'decimal:2', 'line_total' => 'decimal:2'];
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }
}
