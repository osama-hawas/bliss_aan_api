<?php

namespace Database\Seeders;

use App\Models\ServiceCategory;
use Illuminate\Database\Seeder;

/**
 * Provider categories named in the BRD (docs/01 §1). Arabic names to be confirmed against the designs.
 */
class ServiceCategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            ['en' => 'Event planning', 'ar' => 'تنظيم المناسبات'],
            ['en' => 'Venues', 'ar' => 'القاعات'],
            ['en' => 'Photography', 'ar' => 'التصوير'],
            ['en' => 'Catering', 'ar' => 'الضيافة'],
            ['en' => 'Coffee servers', 'ar' => 'صبابات القهوة'],
            ['en' => 'Bridesmaids', 'ar' => 'الوصيفات'],
            ['en' => 'Makeup', 'ar' => 'المكياج'],
            ['en' => 'Flowers & gifts', 'ar' => 'الورود والهدايا'],
        ];

        foreach ($categories as $sort => $name) {
            ServiceCategory::query()->firstOrNew(['name->en' => $name['en']])
                ->fill(['name' => $name, 'sort' => $sort + 1])
                ->save();
        }
    }
}
