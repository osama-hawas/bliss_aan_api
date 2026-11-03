<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A catalog add-on a provider offers, at her price, with some of her services.
 */
class ProviderAddon extends Model
{
    protected $fillable = ['provider_id', 'addon_id', 'price', 'is_active'];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    public function addon(): BelongsTo
    {
        return $this->belongsTo(Addon::class);
    }

    public function services(): BelongsToMany
    {
        return $this->belongsToMany(ProviderService::class, 'provider_addon_service')->withTimestamps();
    }
}
