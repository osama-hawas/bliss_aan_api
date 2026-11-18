<?php

namespace Database\Seeders;

use App\Models\AppSetting;
use App\Services\Settings\AppSettings;
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
            // Initial values (owner instruction 2026-11-17, editable in Settings): without them nobody can sign in.
            ...InitialSettings::OTP,
            'offers.validity_days' => [3, 7, 14], // D5
            'bookings.buffer_minutes' => 0, // D108
            'bookings.start_early_minutes' => 60, // D126
            // Initial values (owner instruction 2026-11-17: "حط قيم مبدأيه والادمن يقدر يغيرها في اي وقت من الاعدادات").
            // They are only the value a key gets when it is created; the admin changes them any time in Settings, and
            // an existing row (even `null`) is never touched. Slice 11 (D215, D216).
            'payouts.commission_percent' => '10.00', // % of each paid, completed booking
            'payouts.hold_days' => 7,                // days before earnings become available
            'payouts.min_amount' => null,            // no minimum payout
            'support.auto_close_days' => null, // D160 (S12b): no auto-close until decided
            // Slice 10 (S10).
            'payments.booking_payment_required' => false, // D185: off = D113 (accept confirms at once)
            'payments.intent_ttl_minutes' => 30, // D191: unpaid attempt lifetime (technical timeout)
            // S9b (Slice 9b store orders): business numbers stay unset until decided.
            'store.shipping_fee' => '25.00',   // D201: flat fee, SAR (initial value, editable)
            'store.max_qty_per_line' => null,  // D208: null = current stock only
            'tax.vat_percent' => '15.00',      // D202: prices include VAT; Saudi standard rate (initial value, editable)
            // Slice 17 (D260): technical chat-review window in minutes, not a business number; null = reason on every page
            'support.chat_access_minutes' => 30,
            // S18 (Slice 18) — low-stock level for the dashboard; null = no low-stock flag (D278).
            'store.low_stock_threshold' => 5, // items (initial value, editable)
            // Slice 19 (S19, D288): initial limits, editable; null approval amount would mean always four-eyes.
            'finance.adjustment_max_amount' => '5000.00',
            'finance.adjustment_approval_amount' => '1000.00',
            // S21 — reports (D320): the overview's low-stock item reads `store.low_stock_threshold` (S18's key above) and stays
            // hidden while it is null.
            // S22 (D330): grace period before an account deletion request is processed (initial value, editable).
            'account.deletion_grace_days' => 14, // days
            // S23 — store returns (OD37, D343): days after delivery a client may ask for a return (initial value, editable).
            'store.return_window_days' => 7,
        ];

        // Initial upload limits (owner instruction 2026-11-17, editable in Settings): with none, every upload is refused.
        $settings = [...$settings, ...InitialSettings::uploads()];

        foreach ($settings as $key => $value) {
            AppSetting::query()->firstOrCreate(['key' => $key], ['value' => $value]);
        }

        // Settings are cached forever: drop the cache so a deploy's seed is read at once.
        app(AppSettings::class)->flush();
    }
}
