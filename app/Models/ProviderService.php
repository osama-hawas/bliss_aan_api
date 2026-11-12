<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A catalog service a provider offers. `custom_price` null = follow the admin guide price.
 * `at_event_location` is what she offers; `event_location_confirmed_at` is set only by her explicit
 * confirmation (D73) and is what open-request matching trusts. The confirmation is not mass assignable; both fields
 * are written only by ProviderSetup::setEventLocation() (PUT event-location) and ProviderSetup::addService() when she
 * offers the service with the event location, each requiring `confirm: true`. The general service update refuses
 * `at_event_location`.
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
            'event_location_confirmed_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Offered by the provider with at least one execution place (D74), and still active in the catalog (service and
     * category).
     */
    public function scopeOffered(Builder $query): void
    {
        $query->where('provider_services.is_active', true)
            ->where(fn (Builder $place) => $place->where('provider_services.at_event_location', true)
                ->orWhere('provider_services.at_provider_location', true))
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
     * Offered at the client's event location and confirmed by her (D73). The one place open-request matching
     * checks this capability; direct requests read `at_event_location` as stored.
     */
    public function scopeOpenRequestCapable(Builder $query): void
    {
        $query->where('provider_services.at_event_location', true)
            ->whereNotNull('provider_services.event_location_confirmed_at');
    }

    /**
     * At the client's event location and/or at her own location (D74). Without one the service is hidden from clients.
     */
    public function hasExecutionPlace(): bool
    {
        return (bool) $this->at_event_location || (bool) $this->at_provider_location;
    }

    /**
     * On from before D73 (or set without a confirmation): she still has to review it.
     */
    public function eventLocationNeedsReview(): bool
    {
        return $this->at_event_location && $this->event_location_confirmed_at === null;
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
