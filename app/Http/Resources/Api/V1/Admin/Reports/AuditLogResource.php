<?php

namespace App\Http\Resources\Api\V1\Admin\Reports;

use App\Http\Resources\Concerns\FormatsApiValues;
use App\Models\AuditLog;
use App\Services\Audit\AuditLabels;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One action log row (`audit.view`, D321). `actor` / `subject` are display strings for the table; the structured
 * values sit next to them. `subject_view` is set by AuditSubjects.
 *
 * @mixin AuditLog
 */
class AuditLogResource extends JsonResource
{
    use FormatsApiValues;

    public function toArray(Request $request): array
    {
        $subject = $this->getAttribute('subject_view');

        return [
            'id' => $this->id,
            'actor' => $this->actor?->name ?? __('reports.system'),
            'actor_id' => $this->actor_id,
            'action' => $this->action,
            'action_label' => AuditLabels::action($this->action),
            'subject' => $subject ? $subject['type_label'].' · '.$subject['name'] : null,
            'subject_type' => $subject['type'] ?? null,
            'subject_type_label' => $subject['type_label'] ?? null,
            'subject_id' => $this->subject_id,
            'subject_name' => $subject['name'] ?? null,
            'subject_href' => $subject['href'] ?? null,
            'reason' => $this->reason,
            'created_at' => $this->dateTime($this->created_at),
        ];
    }
}
