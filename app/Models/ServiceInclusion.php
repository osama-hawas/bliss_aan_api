<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Translatable\HasTranslations;

class ServiceInclusion extends Model
{
    use HasTranslations;

    public array $translatable = ['text'];

    /**
     * Same default as the column (D179), so a new model reads it before a refresh.
     */
    protected $attributes = ['is_active' => true];

    protected $fillable = ['service_id', 'text', 'sort', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }
}
