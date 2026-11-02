<?php

namespace Database\Seeders;

use App\Enums\UploadContext;
use App\Models\AppSetting;
use Illuminate\Database\Seeder;

/**
 * Settings keys the platform reads. `null` = not specified yet (never guessed).
 * Existing values are never overwritten, so admin edits survive re-seeding.
 */
class AppSettingSeeder extends Seeder
{
    public function run(): void
    {
        $settings = [
            'otp.length' => 4,               // D3
            'otp.expiry_seconds' => null,
            'otp.max_attempts' => null,
            'otp.resend_cooldown_seconds' => null,
            'offers.validity_days' => [3, 7, 14], // D5
        ];

        foreach (UploadContext::cases() as $context) {
            $settings[$context->settingKey()] = null; // file limits undecided (BRD M8)
        }

        foreach ($settings as $key => $value) {
            AppSetting::query()->firstOrCreate(['key' => $key], ['value' => $value]);
        }
    }
}
