<?php

namespace Database\Seeders;

use App\Models\EventType;
use Illuminate\Database\Seeder;

/**
 * Occasion types named in the BRD (weddings, engagements, events).
 */
class EventTypeSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            ['en' => 'Wedding', 'ar' => 'زفاف'],
            ['en' => 'Engagement', 'ar' => 'خطوبة'],
            ['en' => 'Other occasion', 'ar' => 'مناسبة أخرى'],
        ];

        foreach ($types as $sort => $name) {
            EventType::query()->firstOrNew(['name->en' => $name['en']])
                ->fill(['name' => $name, 'sort' => $sort + 1])
                ->save();
        }
    }
}
