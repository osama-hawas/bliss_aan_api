<?php

namespace App\Models;

use App\Enums\ProviderAccountType;
use App\Enums\ProviderStatus;
use App\Enums\UserStatus;
use App\Models\Concerns\HasMedia;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * A provider (individual or establishment) owned by one `provider` user.
 * Avatar and cover are media collections `avatar` and `cover`.
 */
class Provider extends Model
{
    use HasMedia;

    protected $fillable = [
        'user_id', 'account_type', 'display_name', 'legal_name', 'representative_name', 'representative_title',
        'bio', 'city_id', 'started_year', 'status', 'status_reason', 'submitted_at', 'is_verified', 'is_featured', 'accepts_open_requests',
        'quick_offer_enabled', 'rating_avg', 'rating_count', 'response_time_minutes', 'activated_at',
    ];

    protected function casts(): array
    {
        return [
            'account_type' => ProviderAccountType::class,
            'status' => ProviderStatus::class,
            'is_verified' => 'boolean',
            'is_featured' => 'boolean',
            'accepts_open_requests' => 'boolean',
            'quick_offer_enabled' => 'boolean',
            'rating_avg' => 'decimal:2',
            'rating_count' => 'integer',
            'response_time_minutes' => 'integer',
            'started_year' => 'integer',
            'activated_at' => 'datetime',
            'submitted_at' => 'datetime',
        ];
    }

    /**
     * Visible to clients: an active application whose owner account is not suspended.
     */
    public function scopeListed(Builder $query): void
    {
        $query->where('providers.status', ProviderStatus::Active)
            ->whereHas('user', fn (Builder $user) => $user->where('status', '!=', UserStatus::Suspended));
    }

    /**
     * Shown to clients (D34 as amended by D75): listed, with at least one offered service, i.e. one with an execution
     * place (D74). One is enough. An unconfirmed event location still counts: D73 confirmation gates open-request
     * matching only. Never changes her status.
     */
    public function scopeVisibleToClients(Builder $query): void
    {
        $query->listed()->whereHas('services', fn (Builder $services) => $services->offered());
    }

    public function avatar(): MorphOne
    {
        return $this->mediaIn('avatar');
    }

    public function cover(): MorphOne
    {
        return $this->mediaIn('cover');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function services(): HasMany
    {
        return $this->hasMany(ProviderService::class);
    }

    public function addons(): HasMany
    {
        return $this->hasMany(ProviderAddon::class);
    }

    public function coverageAreas(): HasMany
    {
        return $this->hasMany(ProviderCoverageArea::class);
    }

    public function coverageCities(): BelongsToMany
    {
        return $this->belongsToMany(City::class, 'provider_coverage_areas')->distinct();
    }

    public function portfolioItems(): HasMany
    {
        return $this->hasMany(PortfolioItem::class)->orderBy('sort')->orderBy('id');
    }

    /**
     * Active availability blocks (soft-deleted ones are excluded, D94).
     */
    public function availabilityBlocks(): HasMany
    {
        return $this->hasMany(AvailabilityBlock::class);
    }

    public function workingHours(): HasMany
    {
        return $this->hasMany(ProviderWorkingHour::class)->orderBy('weekday');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(ProviderDocument::class);
    }

    /**
     * Newest first.
     */
    public function reviewNotes(): HasMany
    {
        return $this->hasMany(ProviderReviewNote::class)->latest('id');
    }
}
