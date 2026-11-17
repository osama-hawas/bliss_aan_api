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
            // Slice 11 (D215, D216): not specified until the owner sets them (null = NOT_CONFIGURED / no minimum).
            'payouts.commission_percent' => null,
            'payouts.hold_days' => null,
            'payouts.min_amount' => null,
            'support.auto_close_days' => null, // D160 (S12b): no auto-close until decided
            // Slice 10 (S10).
            'payments.booking_payment_required' => false, // D185: off = D113 (accept confirms at once)
            'payments.intent_ttl_minutes' => 30, // D191: unpaid attempt lifetime (technical timeout)
            // S9b (Slice 9b store orders): business numbers stay unset until decided.
            'store.shipping_fee' => null,      // D201: flat fee; null → checkout 409 NOT_CONFIGURED
            'store.max_qty_per_line' => null,  // D208: null = current stock only
            'tax.vat_percent' => null,         // D202: prices include VAT; null = no VAT line
            // Slice 17 (D260): technical chat-review window in minutes, not a business number; null = reason on every page
            'support.chat_access_minutes' => 30,
            // S18 (Slice 18) — low-stock level for the dashboard; null = no low-stock flag (D278).
            'store.low_stock_threshold' => null,
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
