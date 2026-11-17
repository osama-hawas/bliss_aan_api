<?php

namespace App\Models;

use App\Enums\DeletionReason;
use App\Enums\DeletionRequestStatus;
use App\Enums\UserType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A request to delete her account (Req 06, Slice 12a), processed by S22's `AccountDeletionProcessor` (D330–D336).
 */
class AccountDeletionRequest extends Model
{
    protected $fillable = ['user_id', 'open_user_id', 'reason_code', 'details', 'status', 'user_type', 'withdrawn_at',
        // S22 (D333, D336).
        'processed_by', 'processed_at', 'internal_note', 'blocked_by', 'checked_at'];

    protected function casts(): array
    {
        return [
            'reason_code' => DeletionReason::class,
            'status' => DeletionRequestStatus::class,
            'user_type' => UserType::class,
            'withdrawn_at' => 'datetime',
            'processed_at' => 'datetime',
            'blocked_by' => 'array',
            'checked_at' => 'datetime',
        ];
    }

    public function processor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
