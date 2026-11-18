<?php

use App\Models\AppSetting;
use App\Services\Settings\AppSettings;
use Database\Seeders\InitialSettings;
use Illuminate\Database\Migrations\Migration;

/**
 * Servers seeded before 2026-12-05 got `null` for the OTP and upload settings (only DemoSeeder filled them), so sign-in
 * and every upload answered NOT_CONFIGURED. Runs once: fills only keys that are still empty, never an admin's value.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (InitialSettings::all() as $key => $value) {
            $row = AppSetting::query()->firstOrCreate(['key' => $key], ['value' => $value]);
            if ($row->value === null) {
                $row->update(['value' => $value]);
            }
        }
        app(AppSettings::class)->flush();
    }

    public function down(): void
    {
        // Values may have been edited since; nothing to undo safely.
    }
};
