<?php

namespace App\Models;

use App\Enums\RecipientStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One provider who received a request: the direct target, or a matching provider of an open request at creation
 * time (D55). Drives the provider inbox. `created_at` is when she received it.
 */
class ServiceRequestRecipient extends Model
{
    protected $fillable = ['service_request_id', 'provider_id', 'status', 'decline_reason_id', 'decline_explanation', 'declined_at', 'seen_at', 'closed_at'];

    protected function casts(): array
    {
        return [
            'status' => RecipientStatus::class,
            'declined_at' => 'datetime',
            'seen_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function serviceRequest(): BelongsTo
    {
        return $this->belongsTo(ServiceRequest::class);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    public function declineReason(): BelongsTo
    {
        return $this->belongsTo(DeclineReason::class);
    }
}
