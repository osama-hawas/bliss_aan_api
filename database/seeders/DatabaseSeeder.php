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
            StoreReturnPolicySeeder::class, // S23 (D348): first store_return policy text, never overwritten
            OnboardingSlideSeeder::class, // Slice 25 (D368): only when an app has no slides yet
        ]);

        if (app()->environment('local')) {
            $this->call(DemoSeeder::class);
            $this->call(StoreDemoSeeder::class); // Slice 9 [DEMO] products
        }
    }
}
