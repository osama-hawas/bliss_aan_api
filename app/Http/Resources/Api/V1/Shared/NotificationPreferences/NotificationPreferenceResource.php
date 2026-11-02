<?php

namespace App\Http\Resources\Api\V1\Shared\NotificationPreferences;

use App\Enums\NotificationPreferenceKey;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property array{key: NotificationPreferenceKey, enabled: bool} $resource
 */
class NotificationPreferenceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'key' => $this->resource['key']->value,
            'label' => $this->resource['key']->label(),
            'enabled' => $this->resource['enabled'],
        ];
    }
}
