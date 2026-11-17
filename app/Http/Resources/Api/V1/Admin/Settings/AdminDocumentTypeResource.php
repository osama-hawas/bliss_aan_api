<?php

namespace App\Http\Resources\Api\V1\Admin\Settings;

use App\Http\Resources\Concerns\FormatsApiValues;
use App\Models\DocumentType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DocumentType
 */
class AdminDocumentTypeResource extends JsonResource
{
    use FormatsApiValues;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => ['ar' => (string) $this->getTranslation('name', 'ar', false), 'en' => (string) $this->getTranslation('name', 'en', false)],
            'applies_to' => $this->applies_to->value,
            'applies_to_label' => $this->applies_to->label(),
            'is_required' => (bool) $this->is_required,
            'allowed_mimes' => $this->allowed_mimes,
            'max_size_kb' => $this->max_size_kb,
            'is_active' => (bool) $this->is_active,
            'sort' => $this->sort,
            'documents_count' => (int) ($this->documents_count ?? 0),
            'created_at' => $this->dateTime($this->created_at),
            'updated_at' => $this->dateTime($this->updated_at),
        ];
    }
}
