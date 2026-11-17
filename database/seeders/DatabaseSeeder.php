<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Reference data that is confirmed (safe for every environment).
     * Fake demo content only runs locally.
     */
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            CitySeeder::class,
            ServiceCategorySeeder::class,
            EventTypeSeeder::class,
            DeclineReasonSeeder::class,
            AppSettingSeeder::class,
            OnboardingSlideSeeder::class, // Slice 25 (D368): only when an app has no slides yet
        ]);

        if (app()->environment('local')) {
            $this->call(DemoSeeder::class);
            $this->call(StoreDemoSeeder::class); // Slice 9 [DEMO] products
        }
    }
}
