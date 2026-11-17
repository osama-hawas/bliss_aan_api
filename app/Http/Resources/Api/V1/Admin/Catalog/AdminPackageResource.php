<?php

namespace App\Http\Resources\Api\V1\Admin\Catalog;

use App\Http\Resources\Concerns\FormatsApiValues;
use App\Models\EventType;
use App\Models\Package;
use App\Models\Service;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A service package for the dashboard (Slice 24 §4). No price (D350). `services` in package order with their own state;
 * `is_visible_in_app` = active with at least one visible service (D352). `requests_count` = requests made from it.
 *
 * @mixin Package
 */
class AdminPackageResource extends JsonResource
{
    use DescribesCatalogRow, FormatsApiValues;

    public function toArray(Request $request): array
    {
        $visible = fn (Service $s) => (bool) $s->is_active && (bool) $s->category?->is_active;

        return [
            'id' => $this->id,
            'name' => $this->both($this->resource, 'name'),
            'description' => $this->both($this->resource, 'description'),
            'image' => $this->imageOf($this->image),
            ...$this->state((bool) $this->is_active),
            'stop_reason' => $this->is_active ? null : $this->stop_reason,
            'sort' => (int) $this->sort,
            'services' => $this->services->map(fn (Service $s) => [
                'id' => $s->id,
                'name' => $this->both($s, 'name'),
                'is_active' => (bool) $s->is_active,
                'is_visible_in_catalog' => $visible($s),
            ])->values(),
            'services_count' => (int) $this->services_count,
            'visible_services_count' => $this->services->filter($visible)->count(),
            'event_types' => $this->eventTypes->map(fn (EventType $t) => ['id' => $t->id, 'name' => $this->both($t, 'name'), 'is_active' => (bool) $t->is_active])->values(),
            'is_visible_in_app' => (bool) $this->is_active && $this->services->contains($visible),
            'requests_count' => (int) $this->requests_count,
            'created_at' => $this->dateTime($this->created_at),
            'updated_at' => $this->dateTime($this->updated_at),
        ];
    }
}
