<?php

use App\Models\AppSetting;
use App\Services\Settings\AppSettings;
use Database\Seeders\CitySeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * City centers + the maximum distance of an address from its city (QA BUG-006). Fills the launch cities' centers
 * and the setting only where they are still empty, never an admin's value.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cities', function (Blueprint $table) {
            $table->decimal('center_lat', 10, 7)->nullable()->after('sort');
            $table->decimal('center_lng', 10, 7)->nullable()->after('center_lat');
        });

        foreach (CitySeeder::CENTERS as $en => [$lat, $lng]) {
            DB::table('cities')->where('name->en', $en)->whereNull('center_lat')
                ->update(['center_lat' => $lat, 'center_lng' => $lng]);
        }

        $row = AppSetting::query()->firstOrCreate(['key' => 'requests.max_distance_from_city_km'], ['value' => 60]);
        if ($row->value === null) {
            $row->update(['value' => 60]);
        }
        app(AppSettings::class)->flush();
    }

    public function down(): void
    {
        Schema::table('cities', function (Blueprint $table) {
            $table->dropColumn(['center_lat', 'center_lng']);
        });
    }
};
