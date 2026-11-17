<?php

namespace App\Http\Resources\Api\V1\Admin\Reviews;

use App\Http\Resources\Concerns\FormatsApiValues;
use App\Models\ContentReport;
use App\Models\Review;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/**
 * Dashboard content report (`reviews.moderate`, D262). Targets are reviews only today.
 *
 * @mixin ContentReport
 */
class AdminContentReportResource extends JsonResource
{
    use FormatsApiValues;

    public function toArray(Request $request): array
    {
        $r = $this->resource;
        $target = $r->reportable;

        return [
            'id' => $r->id,
            'status' => $r->status->value,
            'status_label' => $r->status->label(),
            'decision' => $r->decision?->value,
            'decision_label' => $r->decision?->label(),
            'target_type' => 'review',
            'target' => $target instanceof Review ? [
                'id' => $target->id,
                'label' => Str::limit((string) ($target->comment ?? ''), 80) ?: '★ '.$target->rating,
                'rating' => $target->rating,
                'comment' => $target->comment,
                'status' => $target->status->value,
                'status_label' => $target->status->label(),
                'provider_id' => $target->provider_id,
                'booking_id' => $target->booking_id,
            ] : null,
            'reason' => $r->reason_code->value,
            'reason_label' => $r->reason_code->label(),
            'details' => $r->details,
            'reporter' => ['type' => $r->reporter?->type?->value, 'id' => $r->reporter_id, 'name' => $r->reporter?->name],
            'handled_by' => $r->handled_by ? ['id' => $r->handled_by, 'name' => $r->handler?->name] : null,
            'decision_reason' => $r->decision_reason,
            'internal_note' => $r->internal_note,
            'handled_at' => $this->dateTime($r->handled_at),
            'created_at' => $this->dateTime($r->created_at),
        ];
    }
}
