<?php

namespace App\Models;

use App\Enums\DeletionReason;
use App\Enums\DeletionRequestStatus;
use App\Enums\UserType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A request to delete her account (Req 06, Slice 12a). Never processed in this slice (D155).
 */
class AccountDeletionRequest extends Model
{
    protected $fillable = ['user_id', 'open_user_id', 'reason_code', 'details', 'status', 'user_type', 'withdrawn_at'];

    protected function casts(): array
    {
        return [
            'reason_code' => DeletionReason::class,
            'status' => DeletionRequestStatus::class,
            'user_type' => UserType::class,
            'withdrawn_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
