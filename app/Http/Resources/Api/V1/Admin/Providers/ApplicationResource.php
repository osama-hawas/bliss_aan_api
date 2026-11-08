<?php

namespace App\Http\Resources\Api\V1\Admin\Providers;

use App\Http\Resources\Concerns\FormatsApiValues;
use App\Models\Provider;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A row of the review queue. Admin endpoints return editable text in both languages (`{ar, en}`).
 *
 * @mixin Provider
 */
class ApplicationResource extends JsonResource
{
    use FormatsApiValues;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'provider_id' => $this->id,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'name' => $this->display_name,
            'account_type' => $this->account_type->value,
            'account_type_label' => $this->account_type->label(),
            'city_id' => $this->city_id,
            'city' => $this->city?->getTranslations('name') ?: null,
            'phone' => $this->user->phone,
            'services_count' => (int) $this->services_count,
            'documents_total' => (int) $this->documents_count,
            'documents_pending' => (int) $this->documents_pending_count,
            'submitted_at' => $this->dateTime($this->submitted_at),
            'created_at' => $this->dateTime($this->created_at),
        ];
    }
}
