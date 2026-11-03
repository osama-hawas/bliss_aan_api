<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A catalog service a provider offers. `custom_price` null = follow the admin guide price.
 */
class ProviderService extends Model
{
    protected $fillable = ['provider_id', 'service_id', 'custom_price', 'at_provider_location', 'at_event_location', 'is_active'];

    protected function casts(): array
    {
        return [
            'custom_price' => 'decimal:2',
            'at_provider_location' => 'boolean',
            'at_event_location' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Offered by the provider and still active in the catalog (service and category).
     */
    public function scopeOffered(Builder $query): void
    {
        $query->where('provider_services.is_active', true)
            ->whereHas('service', fn (Builder $service) => $service->where('is_active', true)
                ->whereHas('category', fn (Builder $category) => $category->where('is_active', true)));
    }

    /**
     * Offered, by a provider that clients can see.
     */
    public function scopeVisible(Builder $query): void
    {
        $query->offered()->whereHas('provider', fn (Builder $provider) => $provider->listed());
    }

    /**
     * The price a client sees: the provider's own price, else the current guide price, else null (not set).
     */
    public function price(): ?string
    {
        return $this->custom_price ?? $this->service->guide_price;
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function inclusionSettings(): HasMany
    {
        return $this->hasMany(ProviderServiceInclusion::class);
    }

    public function addons(): BelongsToMany
    {
        return $this->belongsToMany(ProviderAddon::class, 'provider_addon_service')->withTimestamps();
    }
}
