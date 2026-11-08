<?php

namespace App\Http\Resources\Api\V1\Provider\Setup;

use App\Http\Resources\Concerns\FormatsApiValues;
use App\Models\ProviderAddon;
use App\Models\ProviderService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One service she offers: her price (custom, else the guide price), places, inclusions on/off, her add-ons.
 *
 * @mixin ProviderService
 */
class ProviderServiceResource extends JsonResource
{
    use FormatsApiValues;

    public function toArray(Request $request): array
    {
        $disabled = $this->inclusionSettings->where('enabled', false)->pluck('service_inclusion_id')->all();

        return [
            'id' => $this->id,
            'service' => [
                'id' => $this->service->id,
                'name' => $this->service->name,
                'category' => ['id' => $this->service->category->id, 'name' => $this->service->category->name],
            ],
            'guide_price' => $this->money($this->service->guide_price),
            'custom_price' => $this->money($this->custom_price),
            'price' => $this->money($this->price()),
            'uses_guide_price' => $this->custom_price === null,
            'at_provider_location' => $this->at_provider_location,
            'at_event_location' => $this->at_event_location,
            'is_active' => $this->is_active,
            'inclusions' => $this->service->inclusions->map(fn ($i) => [
                'id' => $i->id,
                'text' => $i->text,
                'enabled' => ! in_array($i->id, $disabled, true),
            ])->values(),
            'addons' => $this->addons->map(fn (ProviderAddon $a) => [
                'id' => $a->id,
                'addon' => ['id' => $a->addon->id, 'name' => $a->addon->name],
                'price' => $this->money($a->price),
                'is_active' => $a->is_active,
            ])->values(),
        ];
    }

    public static function load(ProviderService $offered): ProviderService
    {
        return $offered->load(['service.category', 'service.inclusions', 'inclusionSettings', 'addons.addon']);
    }
}
