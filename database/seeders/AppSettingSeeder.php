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
            'requests.max_attachments' => null, // D53, Q14: not approved
            'bookings.buffer_minutes' => 0, // D108
            'bookings.start_early_minutes' => 60, // D126
            'support.auto_close_days' => null, // D160 (S12b): no auto-close until decided
            // S9b (Slice 9b store orders): business numbers stay unset until decided.
            'store.shipping_fee' => null,      // D201: flat fee; null → checkout 409 NOT_CONFIGURED
            'store.max_qty_per_line' => null,  // D208: null = current stock only
            'tax.vat_percent' => null,         // D202: prices include VAT; null = no VAT line
        ];

        foreach (UploadContext::cases() as $context) {
            $settings[$context->settingKey()] = null; // file limits undecided (BRD M8)
        }

        foreach ($settings as $key => $value) {
            AppSetting::query()->firstOrCreate(['key' => $key], ['value' => $value]);
        }
    }
}
