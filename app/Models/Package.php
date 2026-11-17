<?php

namespace App\Models;

use App\Models\Concerns\HasMedia;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Spatie\Translatable\HasTranslations;

/**
 * An admin-curated bundle of catalog services (OD39, Slice 24). **No price** (D350): providers price each service through
 * offers, as for any request. Stopped / activated, never deleted (D180).
 */
class Package extends Model
{
    use HasMedia, HasTranslations;

    public array $translatable = ['name', 'description'];

    protected $fillable = ['name', 'description', 'is_active', 'stop_reason', 'sort'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function image(): MorphOne
    {
        return $this->mediaIn('image');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PackageItem::class)->orderBy('sort')->orderBy('id');
    }

    /**
     * Every linked service, any status, in package order.
     */
    public function services(): BelongsToMany
    {
        return $this->belongsToMany(Service::class, 'package_items')
            ->withPivot('sort')
            ->withTimestamps()
            ->orderBy('package_items.sort')
            ->orderBy('package_items.id');
    }

    /**
     * Linked services clients can see: service and category active (the same rule as `CatalogBrowser`, D34).
     */
    public function visibleServices(): BelongsToMany
    {
        return $this->services()
            ->where('services.is_active', true)
            ->whereHas('category', fn (Builder $category) => $category->where('is_active', true));
    }

    public function eventTypes(): BelongsToMany
    {
        return $this->belongsToMany(EventType::class)->withTimestamps()->orderBy('event_types.sort')->orderBy('event_types.id');
    }

    public function serviceRequests(): HasMany
    {
        return $this->hasMany(ServiceRequest::class);
    }

    /**
     * Shown to clients: active with at least one visible service (D352).
     */
    public function scopeVisibleToClients(Builder $query): void
    {
        $query->where('packages.is_active', true)
            ->whereHas('services', fn (Builder $service) => $service->where('services.is_active', true)
                ->whereHas('category', fn (Builder $category) => $category->where('is_active', true)));
    }
}
