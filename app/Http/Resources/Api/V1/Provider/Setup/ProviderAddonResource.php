<?php

namespace App\Http\Resources\Api\V1\Provider\Setup;

use App\Http\Resources\Concerns\FormatsApiValues;
use App\Models\ProviderAddon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ProviderAddon
 */
class ProviderAddonResource extends JsonResource
{
    use FormatsApiValues;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'addon' => ['id' => $this->addon->id, 'name' => $this->addon->name],
            'price' => $this->money($this->price),
            'is_active' => $this->is_active,
            'provider_service_ids' => $this->services->pluck('id')->values(),
        ];
    }
}
