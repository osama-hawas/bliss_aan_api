<?php

namespace App\Models;

use App\Enums\MediaVisibility;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Media extends Model
{
    protected $fillable = ['attachable_type', 'attachable_id', 'collection', 'disk', 'path', 'original_name', 'mime', 'size', 'duration', 'visibility', 'uploaded_by'];

    protected $hidden = ['disk', 'path'];

    protected function casts(): array
    {
        return [
            'visibility' => MediaVisibility::class,
            'size' => 'integer',
            'duration' => 'integer',
        ];
    }

    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function isPublic(): bool
    {
        return $this->visibility === MediaVisibility::Public;
    }
}
