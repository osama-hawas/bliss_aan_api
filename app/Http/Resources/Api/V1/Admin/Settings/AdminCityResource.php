<?php

namespace App\Http\Resources\Api\V1\Admin\Settings;

use App\Http\Resources\Concerns\FormatsApiValues;
use App\Models\City;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin City
 */
class AdminCityResource extends JsonResource
{
    use FormatsApiValues;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => ['ar' => (string) $this->getTranslation('name', 'ar', false), 'en' => (string) $this->getTranslation('name', 'en', false)],
            'is_active' => (bool) $this->is_active,
            'sort' => $this->sort,
            'providers_count' => (int) ($this->providers_count ?? 0),
            'clients_count' => (int) ($this->clients_count ?? 0),
            'created_at' => $this->dateTime($this->created_at),
            'updated_at' => $this->dateTime($this->updated_at),
        ];
    }
}
