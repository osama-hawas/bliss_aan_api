<?php

namespace App\Services\Audit;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Logs admin and sensitive actions with the reason and before/after values.
 */
class AuditLogger
{
    public function log(
        string $action,
        ?User $actor,
        ?Model $subject = null,
        ?string $reason = null,
        ?array $old = null,
        ?array $new = null,
    ): AuditLog {
        return AuditLog::query()->create([
            'actor_id' => $actor?->id,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'action' => $action,
            'reason' => $reason,
            'old' => $old,
            'new' => $new,
        ]);
    }
}
