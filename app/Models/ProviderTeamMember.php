<?php

namespace App\Models;

use App\Enums\TeamMemberStatus;
use App\Models\Concerns\HasMedia;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * A member of a provider's team (Slice 13, D230): a record only, never a login. Photo = media collection `team_photo`
 * (private, D235). `user_id` stays null (kept for a later login, 02-database-plan §12).
 */
class ProviderTeamMember extends Model
{
    use HasMedia;

    public const PHOTO = 'team_photo';

    protected $fillable = ['provider_id', 'user_id', 'name', 'phone', 'title', 'status', 'disabled_at'];

    protected function casts(): array
    {
        return [
            'status' => TeamMemberStatus::class,
            'disabled_at' => 'datetime',
        ];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    public function photo(): MorphOne
    {
        return $this->mediaIn(self::PHOTO);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(BookingAssignment::class, 'team_member_id');
    }

    public function bookings(): BelongsToMany
    {
        return $this->belongsToMany(Booking::class, 'booking_assignments', 'team_member_id', 'booking_id')->withTimestamps();
    }

    public function isActive(): bool
    {
        return $this->status === TeamMemberStatus::Active;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', TeamMemberStatus::Active);
    }
}
