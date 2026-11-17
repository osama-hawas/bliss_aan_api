<?php

namespace App\Models;

use App\Enums\BookingPaymentStatus;
use App\Enums\BookingStatus;
use App\Enums\CancellationStatus;
use App\Enums\ExecutionPlace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A request settled on one accepted offer (Slice 5c). Times, address, title and total are snapshots (D107).
 */
class Booking extends Model
{
    protected $fillable = [
        'reference', 'service_request_id', 'offer_id', 'client_id', 'provider_id', 'service_id', 'execution_place',
        'starts_at', 'ends_at', 'address', 'title', 'total', 'status', 'payment_status', 'policy_version_id',
        'confirmed_at', 'started_at', 'started_by', 'completed_at', 'completed_by', 'cancelled_at', 'idempotency_key',
        'idempotency_fingerprint',
        'payment_id', // Slice 10 (D185)
    ];

    protected function casts(): array
    {
        return [
            'status' => BookingStatus::class,
            'payment_status' => BookingPaymentStatus::class,
            'execution_place' => ExecutionPlace::class,
            'address' => 'array',
            'total' => 'decimal:2',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * Bookings that make the provider busy (D110).
     */
    public function scopeBusy(Builder $query): Builder
    {
        return $query->whereIn('status', BookingStatus::busy());
    }

    public function serviceRequest(): BelongsTo
    {
        return $this->belongsTo(ServiceRequest::class);
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * Slice 6: every cancellation request of this booking, newest first.
     */
    public function cancellationRequests(): HasMany
    {
        return $this->hasMany(CancellationRequest::class)->orderByDesc('id');
    }

    public function review(): HasOne
    {
        return $this->hasOne(Review::class);
    }

    /**
     * Slice 10 (D185): the payment that confirmed this booking, null when no payment was required.
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function startedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by');
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function pendingCancellation(): ?CancellationRequest
    {
        return $this->cancellationRequests->firstWhere('status', CancellationStatus::UnderReview);
    }

    // --- Slice 13 (S13): the provider's team on this booking (D230), provider only. ---

    public function team(): BelongsToMany
    {
        return $this->belongsToMany(ProviderTeamMember::class, 'booking_assignments', 'booking_id', 'team_member_id')
            ->withTimestamps()
            ->orderBy('booking_assignments.created_at')
            ->orderBy('provider_team_members.id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(BookingAssignment::class);
    }
}
