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
        'bio', 'city_id', 'started_year', 'status', 'is_verified', 'is_featured', 'accepts_open_requests',
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
}
