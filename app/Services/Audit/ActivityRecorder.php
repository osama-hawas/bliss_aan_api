<?php

namespace App\Services\Audit;

use App\Enums\ActivityVisibility;
use App\Models\ActivityEvent;
use App\Models\User;
use BackedEnum;
use Illuminate\Database\Eloquent\Model;

/**
 * Writes the history entry for a state change. Call it inside the same DB transaction
 * as the change itself.
 */
class ActivityRecorder
{
    public function record(
        Model $subject,
        string $event,
        BackedEnum|string|null $from = null,
        BackedEnum|string|null $to = null,
        ?User $actor = null,
        ActivityVisibility $visibility = ActivityVisibility::All,
        array $meta = [],
    ): ActivityEvent {
        return ActivityEvent::query()->create([
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'event' => $event,
            'from_status' => $from instanceof BackedEnum ? $from->value : $from,
            'to_status' => $to instanceof BackedEnum ? $to->value : $to,
            'actor_id' => $actor?->id,
            'actor_role' => $actor?->type?->value,
            'visibility' => $visibility,
            'meta' => $meta ?: null,
        ]);
    }
}
