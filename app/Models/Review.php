<?php

namespace App\Models;

use App\Enums\ReviewStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * The client's review of a completed booking (Req 25, D129). Never edited or deleted; one provider reply.
 */
class Review extends Model
{
    protected $fillable = [
        'booking_id', 'client_id', 'provider_id', 'rating', 'comment', 'tags', 'status', 'hidden_reason',
        'provider_reply', 'replied_at',
        'hidden_by', 'hidden_at', 'moderation_note', // Slice 17 (D261)
    ];

    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            'tags' => 'array',
            'status' => ReviewStatus::class,
            'replied_at' => 'datetime',
            'hidden_at' => 'datetime',
        ];
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', ReviewStatus::Published);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    public function hiddenBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'hidden_by');
    }

    public function reports(): MorphMany
    {
        return $this->morphMany(ContentReport::class, 'reportable');
    }
}
