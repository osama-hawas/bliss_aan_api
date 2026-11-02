<?php

namespace App\Models;

use App\Models\Concerns\HasMedia;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Translatable\HasTranslations;

/**
 * Admin catalog service. The guide price is a default for providers, not a min/max.
 */
class Service extends Model
{
    use HasMedia, HasTranslations;

    public array $translatable = ['name', 'description'];

    protected $fillable = ['category_id', 'name', 'description', 'guide_price', 'is_active', 'sort'];

    protected function casts(): array
    {
        return [
            'guide_price' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ServiceCategory::class, 'category_id');
    }

    public function inclusions(): HasMany
    {
        return $this->hasMany(ServiceInclusion::class)->orderBy('sort');
    }

    public function addons(): BelongsToMany
    {
        return $this->belongsToMany(Addon::class)->withTimestamps();
    }
}
