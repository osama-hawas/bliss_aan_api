<?php

namespace App\Http\Resources\Api\V1\Admin\Catalog;

use App\Models\Addon;
use App\Models\Service;
use App\Models\ServiceInclusion;
use Illuminate\Http\Request;

/**
 * Service detail: every inclusion (active first, then switched off, D179), linked add-ons and the pricing split.
 *
 * @mixin Service
 */
class AdminServiceDetailResource extends AdminServiceResource
{
    public function toArray(Request $request): array
    {
        $inclusions = $this->allInclusions
            ->sortBy(fn (ServiceInclusion $i) => [$i->is_active ? 0 : 1, $i->sort, $i->id])
            ->map(fn (ServiceInclusion $i) => [
                'id' => $i->id,
                'text' => $this->both($i, 'text'),
                'is_active' => (bool) $i->is_active,
                'sort' => (int) $i->sort,
            ])->values();

        return [
            ...parent::toArray($request),
            'inclusions' => $inclusions,
            'addons' => $this->addons->map(fn (Addon $a) => [
                'id' => $a->id,
                'name' => $this->both($a, 'name'),
                'is_active' => (bool) $a->is_active,
            ])->values(),
            'pricing' => [
                'providers_default' => (int) $this->providers_default,
                'providers_custom' => (int) $this->providers_custom,
            ],
        ];
    }
}
