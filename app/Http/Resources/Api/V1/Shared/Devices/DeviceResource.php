<?php

namespace App\Http\Resources\Api\V1\Shared\Devices;

use App\Http\Resources\Concerns\FormatsApiValues;
use App\Models\UserDevice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin UserDevice
 */
class DeviceResource extends JsonResource
{
    use FormatsApiValues;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'app' => $this->app->value,
            'app_label' => $this->app->label(),
            'platform' => $this->platform,
            'device_name' => $this->device_name,
            'is_current' => $this->id === $request->attributes->get('device')?->id,
            'last_seen_at' => $this->dateTime($this->last_seen_at),
            'signed_in_at' => $this->dateTime($this->created_at),
        ];
    }
}
