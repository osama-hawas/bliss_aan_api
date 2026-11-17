<?php

namespace App\Models;

use App\Enums\Audience;
use App\Models\Concerns\HasMedia;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Spatie\Translatable\HasTranslations;

/**
 * One onboarding slide of the client or provider app (Slice 25, D360).
 */
class OnboardingSlide extends Model
{
    use HasMedia, HasTranslations;

    public array $translatable = ['title', 'text'];

    protected $fillable = ['app', 'title', 'text', 'sort', 'is_active', 'hidden_reason'];

    protected function casts(): array
    {
        return [
            'app' => Audience::class,
            'is_active' => 'boolean',
            'sort' => 'integer',
        ];
    }

    public function image(): MorphOne
    {
        return $this->mediaIn('image');
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort')->orderBy('id');
    }
}
