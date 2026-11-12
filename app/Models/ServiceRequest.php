<?php

namespace App\Models;

use App\Enums\ExecutionPlace;
use App\Enums\ServiceRequestStatus;
use App\Enums\ServiceRequestType;
use App\Enums\UploadContext;
use App\Models\Concerns\HasMedia;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * A client's service request (Slice 4). Times: `event_date` + `start_time` as entered in Asia/Riyadh,
 * `starts_at`/`ends_at` in UTC (D48, D50). `address` is the snapshot taken at creation (null for provider location,
 * D66). Attachments are media rows in collection `request_attachment` (D52).
 */
class ServiceRequest extends Model
{
    use HasMedia;

    protected $fillable = [
        'reference', 'client_id', 'type', 'target_provider_id', 'provider_service_id', 'service_id', 'event_type_id',
        'execution_place', 'event_date', 'start_time', 'duration_minutes', 'starts_at', 'ends_at', 'city_id',
        'address_id', 'address', 'guests_count', 'notes', 'status', 'cancelled_at', 'cancel_reason',
        'idempotency_key', 'idempotency_fingerprint',
    ];

    protected $hidden = ['idempotency_key', 'idempotency_fingerprint'];

    protected function casts(): array
    {
        return [
            'type' => ServiceRequestType::class,
            'status' => ServiceRequestStatus::class,
            'execution_place' => ExecutionPlace::class,
            'event_date' => 'date:Y-m-d',
            'duration_minutes' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'address' => 'array',
            'guests_count' => 'integer',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * D49/D62: true once the end time is reached. Display only; it blocks nothing.
     */
    public function eventPassed(): bool
    {
        return now()->greaterThanOrEqualTo($this->ends_at);
    }

    /**
     * `HH:mm` as entered (the column is stored as `HH:mm:ss`).
     */
    public function startTime(): string
    {
        return substr((string) $this->start_time, 0, 5);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    public function targetProvider(): BelongsTo
    {
        return $this->belongsTo(Provider::class, 'target_provider_id');
    }

    public function providerService(): BelongsTo
    {
        return $this->belongsTo(ProviderService::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function eventType(): BelongsTo
    {
        return $this->belongsTo(EventType::class);
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function addons(): HasMany
    {
        return $this->hasMany(ServiceRequestAddon::class)->orderBy('id');
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(ServiceRequestRecipient::class)->orderBy('id');
    }

    public function attachments(): MorphMany
    {
        return $this->media()->where('collection', UploadContext::RequestAttachment->value)->orderBy('id');
    }

    public function history(): MorphMany
    {
        return $this->morphMany(ActivityEvent::class, 'subject')->orderBy('id');
    }
}
