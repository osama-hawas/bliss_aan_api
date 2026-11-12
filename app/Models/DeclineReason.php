<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Spatie\Translatable\HasTranslations;

/**
 * Predefined reasons a provider picks when declining a request (D51). Deactivated, never deleted.
 */
class DeclineReason extends Model
{
    use HasTranslations;

    public array $translatable = ['name'];

    protected $fillable = ['code', 'name', 'is_active', 'sort'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
