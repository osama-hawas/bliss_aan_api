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
            AppSettingSeeder::class,
        ]);

        if (app()->environment('local')) {
            $this->call(DemoSeeder::class);
        }
    }
}
