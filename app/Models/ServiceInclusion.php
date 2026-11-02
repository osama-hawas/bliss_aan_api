<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Translatable\HasTranslations;

class ServiceInclusion extends Model
{
    use HasTranslations;

    public array $translatable = ['text'];

    protected $fillable = ['service_id', 'text', 'sort'];

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }
}
