<?php

namespace App\Models;

use App\Enums\GuestCountMode;
use App\Models\Concerns\HasMedia;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Spatie\Translatable\HasTranslations;

/**
 * Admin catalog service. The guide price is a default for providers, not a min/max.
 */
class Service extends Model
{
    use HasMedia, HasTranslations;

    public array $translatable = ['name', 'description'];

    /**
     * Same default as the column (D54), so a new model reads it before a refresh.
     */
    protected $attributes = ['guest_count_mode' => 'optional'];

    protected $fillable = ['category_id', 'name', 'description', 'guide_price', 'guest_count_mode', 'is_active', 'sort'];

    protected function casts(): array
    {
        return [
            'guide_price' => 'decimal:2',
            'guest_count_mode' => GuestCountMode::class,
            'is_active' => 'boolean',
        ];
    }

    public function image(): MorphOne
    {
        return $this->mediaIn('image');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ServiceCategory::class, 'category_id');
    }

    /**
     * Inclusions that apply: the active ones (D179). Every reader outside the admin catalog uses this.
     */
    public function inclusions(): HasMany
    {
        return $this->hasMany(ServiceInclusion::class)->where('is_active', true)->orderBy('sort')->orderBy('id');
    }

    /**
     * Every inclusion, switched-off ones included (admin catalog only).
     */
    public function allInclusions(): HasMany
    {
        return $this->hasMany(ServiceInclusion::class)->orderBy('sort')->orderBy('id');
    }

    public function providerServices(): HasMany
    {
        return $this->hasMany(ProviderService::class);
    }

    public function addons(): BelongsToMany
    {
        return $this->belongsToMany(Addon::class)->withTimestamps()->orderBy('addons.sort')->orderBy('addons.id');
    }
}
