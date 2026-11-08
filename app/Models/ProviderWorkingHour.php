<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One open weekday (0 = Sunday) with its hours. A weekday without a row is closed.
 */
class ProviderWorkingHour extends Model
{
    protected $fillable = ['provider_id', 'weekday', 'opens_at', 'closes_at'];

    protected function casts(): array
    {
        return ['weekday' => 'integer'];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }
}
