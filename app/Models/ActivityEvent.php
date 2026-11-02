<?php

namespace App\Models;

use App\Enums\ActivityVisibility;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ActivityEvent extends Model
{
    protected $fillable = ['subject_type', 'subject_id', 'event', 'from_status', 'to_status', 'actor_id', 'actor_role', 'visibility', 'meta'];

    protected function casts(): array
    {
        return [
            'visibility' => ActivityVisibility::class,
            'meta' => 'array',
        ];
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
