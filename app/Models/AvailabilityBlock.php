<?php

namespace App\Models;

use App\Support\RiyadhCalendar;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A period a provider is unavailable (Slice 5a). Provider-wide, half-open [starts_at, ends_at) in UTC. The default
 * query sees active blocks only (SoftDeletes, D94).
 */
class AvailabilityBlock extends Model
{
    use SoftDeletes;

    protected $fillable = ['provider_id', 'starts_at', 'ends_at', 'reason', 'created_by'];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    /**
     * Blocks that overlap [from, to): a.starts_at < to and from < a.ends_at (§3.2).
     */
    public function scopeOverlapping(Builder $query, DateTimeInterface $from, DateTimeInterface $to): void
    {
        $query->where('starts_at', '<', $to)->where('ends_at', '>', $from);
    }

    /**
     * Both ends on Riyadh midnight.
     */
    public function wholeDays(): bool
    {
        return RiyadhCalendar::isMidnight($this->starts_at) && RiyadhCalendar::isMidnight($this->ends_at);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }
}
