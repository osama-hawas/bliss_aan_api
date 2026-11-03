<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A city (and later maybe a district, Q7) where the provider works.
 */
class ProviderCoverageArea extends Model
{
    protected $fillable = ['provider_id', 'city_id', 'district_id'];

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }
}
