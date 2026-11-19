<?php

namespace Database\Seeders;

use App\Models\City;
use Illuminate\Database\Seeder;

/**
 * Launch cities (decision D9).
 */
class CitySeeder extends Seeder
{
    /** City centers by English name, for the address distance check (QA BUG-006). */
    public const CENTERS = [
        'Riyadh' => [24.7136, 46.6753],
        'Jeddah' => [21.4858, 39.1925],
        'Dammam' => [26.4207, 50.0888],
        'Makkah' => [21.3891, 39.8579],
        'Madinah' => [24.5247, 39.5692],
        'Khobar' => [26.2172, 50.1971],
    ];

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
                ->fill(['name' => $name, 'sort' => $sort + 1, 'center_lat' => self::CENTERS[$name['en']][0], 'center_lng' => self::CENTERS[$name['en']][1]])
                ->save();
        }
    }
}
