<?php

namespace App\Models;

use App\Enums\Audience;
use App\Models\Concerns\HasMedia;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Spatie\Translatable\HasTranslations;

class Banner extends Model
{
    use HasMedia, HasTranslations;

    public array $translatable = ['title'];

    protected $fillable = ['title', 'destination_type', 'destination_id', 'app', 'city_id', 'starts_at', 'ends_at', 'is_active', 'sort'];

    protected function casts(): array
    {
        return [
            'app' => Audience::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    public function image(): MorphOne
    {
        return $this->mediaIn('image');
    }

    public function destination(): MorphTo
    {
        return $this->morphTo();
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }
}
