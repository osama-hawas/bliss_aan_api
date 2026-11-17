<?php

namespace App\Models;

use App\Models\Concerns\HasMedia;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Spatie\Translatable\HasTranslations;

class Addon extends Model
{
    use HasMedia, HasTranslations;

    public array $translatable = ['name', 'description'];

    protected $fillable = ['name', 'description', 'is_active', 'sort'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function services(): BelongsToMany
    {
        return $this->belongsToMany(Service::class)->withTimestamps();
    }

    public function image(): MorphOne
    {
        return $this->mediaIn('image');
    }

    public function providerAddons(): HasMany
    {
        return $this->hasMany(ProviderAddon::class);
    }
}
