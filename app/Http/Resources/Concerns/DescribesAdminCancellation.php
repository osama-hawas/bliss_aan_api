<?php

namespace App\Http\Resources\Concerns;

use App\Models\CancellationRequest;

/**
 * Dashboard view of a cancellation request (D131): the shared fields plus the requester, the decider and the
 * internal note. Admin resources only.
 */
trait DescribesAdminCancellation
{
    use DescribesBookingExecution;

    protected function adminCancellation(CancellationRequest $c): array
    {
        return [
            ...$this->cancellationItem($c),
            'booking_id' => $c->booking_id,
            'requester' => ['id' => $c->requester_id, 'name' => $c->requester?->name],
            'decided_by' => $c->decided_by ? ['id' => $c->decided_by, 'name' => $c->decider?->name] : null,
            'internal_note' => $c->internal_note,
        ];
    }
}
