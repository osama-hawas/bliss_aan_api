<?php

namespace App\Http\Resources\Api\V1\Shared\Providers;

use App\Models\ProviderAddon;
use App\Models\ProviderService;
use App\Models\ServiceInclusion;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Service details screen. Pass the inclusions and add-ons resolved by ProviderDirectory.
 *
 * @mixin ProviderService
 */
class ProviderServiceDetailResource extends ProviderServiceResource
{
    /**
     * @param  Collection<int, ServiceInclusion>  $inclusions
     * @param  Collection<int, ProviderAddon>  $addons
     */
    public function __construct(ProviderService $resource, private readonly Collection $inclusions, private readonly Collection $addons)
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'inclusions' => $this->inclusions->map(fn (ServiceInclusion $inclusion) => $inclusion->text)->values(),
            'addons' => $this->addons->map(fn (ProviderAddon $addon) => [
                'id' => $addon->id,
                'name' => $addon->addon->name,
                'description' => $addon->addon->description,
                'price' => Money::format($addon->price),
            ])->values(),
        ];
    }
}
