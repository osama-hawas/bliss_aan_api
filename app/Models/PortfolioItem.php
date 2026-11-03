<?php

namespace App\Models;

use App\Enums\MediaVisibility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PortfolioItem extends Model
{
    protected $fillable = ['provider_id', 'media_id', 'caption', 'hidden_by_admin', 'hidden_reason', 'sort'];

    protected function casts(): array
    {
        return ['hidden_by_admin' => 'boolean'];
    }

    /**
     * Shown on the public profile: not hidden by admin, and the file itself is public.
     */
    public function scopePublished(Builder $query): void
    {
        $query->where('hidden_by_admin', false)
            ->whereHas('media', fn (Builder $media) => $media->where('visibility', MediaVisibility::Public));
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class);
    }
}
