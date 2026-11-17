<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One team member on one booking (Slice 13, D230): informational, provider only. Removing it deletes this row; the
 * history stays in `activity_events` on the booking (visibility `provider`).
 */
class BookingAssignment extends Model
{
    protected $fillable = ['booking_id', 'team_member_id', 'assigned_by'];

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(ProviderTeamMember::class, 'team_member_id');
    }
}
