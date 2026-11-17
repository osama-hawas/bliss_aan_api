<?php

namespace App\Http\Resources\Api\V1\Admin\Reports;

use App\Services\Audit\AuditLogQueries;
use Illuminate\Http\Request;

/**
 * An action log entry with its before/after values and the changed fields (D321).
 */
class AuditLogDetailResource extends AuditLogResource
{
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'actor_phone' => $this->actor?->phone,
            'old' => $this->old,
            'new' => $this->new,
            'changes' => AuditLogQueries::changes($this->old, $this->new),
        ];
    }
}
