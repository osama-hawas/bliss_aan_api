<?php

namespace Tests\Feature\Foundation;

use App\Models\AppSetting;
use App\Services\Settings\AppSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InitialSettingsMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_fills_empty_sign_in_and_upload_settings_and_keeps_admin_values(): void
    {
        AppSetting::query()->updateOrCreate(['key' => 'otp.expiry_seconds'], ['value' => null]);
        AppSetting::query()->updateOrCreate(['key' => 'uploads.avatar'], ['value' => null]);
        AppSetting::query()->updateOrCreate(['key' => 'otp.max_attempts'], ['value' => 3]); // the admin's choice
        AppSetting::query()->where('key', 'otp.resend_cooldown_seconds')->delete();
        app(AppSettings::class)->flush();

        $migration = require database_path('migrations/2026_12_05_100000_fill_initial_sign_in_and_upload_settings.php');
        $migration->up();

        $settings = app(AppSettings::class);
        $this->assertSame(300, $settings->required('otp.expiry_seconds'));
        $this->assertSame(60, $settings->required('otp.resend_cooldown_seconds'));
        $this->assertSame(3, $settings->required('otp.max_attempts'));
        $this->assertSame(10240, $settings->required('uploads.avatar')['max_kb']);
    }
}
