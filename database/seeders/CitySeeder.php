<?php

namespace Database\Seeders;

use App\Models\City;
use Illuminate\Database\Seeder;

/**
 * Launch cities (decision D9).
 */
class CitySeeder extends Seeder
{
    public function run(): void
    {
        $cities = [
            ['en' => 'Riyadh', 'ar' => 'الرياض'],
            ['en' => 'Jeddah', 'ar' => 'جدة'],
            ['en' => 'Dammam', 'ar' => 'الدمام'],
            ['en' => 'Makkah', 'ar' => 'مكة المكرمة'],
            ['en' => 'Madinah', 'ar' => 'المدينة المنورة'],
            ['en' => 'Khobar', 'ar' => 'الخبر'],
        ];

        foreach ($cities as $sort => $name) {
            City::query()->firstOrNew(['name->en' => $name['en']])
                ->fill(['name' => $name, 'sort' => $sort + 1])
                ->save();
        }
    }
}
