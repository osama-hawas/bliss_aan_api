<?php

namespace App\Http\Resources\Api\V1\Admin\Settings;

use App\Http\Resources\Concerns\FormatsApiValues;
use App\Models\PolicyVersion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PolicyVersion
 *
 * `published_by` / `is_current` are set by the controller (audit log, PolicyResolver).
 */
class AdminPolicyVersionResource extends JsonResource
{
    use FormatsApiValues;

    public function toArray(Request $request): array
    {
        $content = $this->getTranslations('content');

        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'version' => $this->version,
            'effective_from' => $this->dateTime($this->effective_from),
            'is_current' => (bool) ($this->is_current ?? false),
            'is_scheduled' => $this->effective_from->isFuture(),
            'content' => $content === [] ? null : ['ar' => $content['ar'] ?? '', 'en' => $content['en'] ?? ''],
            'values' => (object) ($this->values ?? []),
            'published_by' => $this->published_by ?? null,
            'created_at' => $this->dateTime($this->created_at),
        ];
    }
}
