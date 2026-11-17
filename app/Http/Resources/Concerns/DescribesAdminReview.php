<?php

namespace App\Http\Resources\Concerns;

use App\Models\Review;

/**
 * Dashboard review fields (Slice 17, D261): the shared review item plus the moderation trail. Admin resources only.
 * Uses FormatsApiValues and DescribesBookingExecution.
 */
trait DescribesAdminReview
{
    protected function adminReview(Review $r): array
    {
        return [
            ...$this->reviewItem($r),
            'status_label' => $r->status->label(),
            'hidden_reason' => $r->hidden_reason,
            'hidden_by' => $r->hidden_by ? ['id' => $r->hidden_by, 'name' => $r->hiddenBy?->name] : null,
            'hidden_at' => $this->dateTime($r->hidden_at),
            'moderation_note' => $r->moderation_note,
        ];
    }
}
