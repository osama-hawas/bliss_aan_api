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
            'requests.max_attachments' => null, // D53; outside local: D242 below
            'bookings.buffer_minutes' => 0, // D108
            'bookings.start_early_minutes' => 60, // D126
            'support.auto_close_days' => null, // D160 (S12b): no auto-close until decided
        ];

        foreach (UploadContext::cases() as $context) {
            $settings[$context->settingKey()] = null; // file limits undecided (BRD M8)
        }

        foreach ($settings as $key => $value) {
            AppSetting::query()->firstOrCreate(['key' => $key], ['value' => $value]);
        }

        $this->requestAttachmentLimits();
    }

    /**
     * D242 (Q14, SFIX): request attachment limits outside `local` — 10 MB per file, 5 files, jpg/jpeg/png/heic/pdf.
     * Filled only where the value is still `null`; an admin value is never overwritten. Local keeps `DemoSeeder`'s values.
     */
    private function requestAttachmentLimits(): void
    {
        if (app()->environment('local')) {
            return;
        }

        $values = [
            UploadContext::RequestAttachment->settingKey() => ['mimes' => ['jpg', 'jpeg', 'png', 'heic', 'pdf'], 'max_kb' => 10240],
            'requests.max_attachments' => 5,
        ];
        foreach ($values as $key => $value) {
            AppSetting::query()->where('key', $key)->whereNull('value')->update(['value' => json_encode($value)]);
        }
    }
}
