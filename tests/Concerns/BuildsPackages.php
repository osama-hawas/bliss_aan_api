<?php

namespace Tests\Concerns;

use App\Models\EventType;
use App\Models\Package;
use App\Models\Service;

/**
 * Slice 24 (docs/slice-24-contract.md): packages written straight to the tables, for the public and request tests.
 */
trait BuildsPackages
{
    /**
     * @param  list<Service>  $services  in display order
     * @param  list<EventType>  $eventTypes
     * @param  array<string, mixed>  $attributes
     */
    protected function package(array $services, string $en = 'Bride day', array $eventTypes = [], array $attributes = []): Package
    {
        $package = Package::query()->create([
            'name' => ['en' => $en, 'ar' => "ar {$en}"],
            'description' => ['en' => "{$en} description", 'ar' => "ar {$en} description"],
            'is_active' => true,
            'sort' => (int) Package::query()->max('sort') + 1,
            ...$attributes,
        ]);
        foreach (array_values($services) as $index => $service) {
            $package->items()->create(['service_id' => $service->id, 'sort' => $index + 1]);
        }
        $package->eventTypes()->sync(collect($eventTypes)->pluck('id')->all());

        return $package;
    }
}
