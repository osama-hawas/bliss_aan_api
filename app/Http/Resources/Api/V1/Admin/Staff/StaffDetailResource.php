<?php

namespace App\Http\Resources\Api\V1\Admin\Staff;

use App\Models\AccountStatusLog;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * A staff member with her last 20 status changes (reason is user-visible, the note is internal).
 *
 * @mixin User
 */
class StaffDetailResource extends StaffResource
{
    public function toArray(Request $request): array
    {
        $history = $this->statusLogs()->with('actor:id,name')->latest('id')->limit(20)->get();

        return [
            ...parent::toArray($request),
            'status_history' => $history->map(fn (AccountStatusLog $log) => [
                'action' => $log->action,
                'reason' => $log->reason,
                'internal_note' => $log->internal_note,
                'actor' => $log->actor?->name,
                'created_at' => $this->dateTime($log->created_at),
            ])->values(),
        ];
    }
}
