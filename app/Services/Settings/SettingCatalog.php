<?php

namespace App\Services\Settings;

use App\Enums\SettingKind;
use App\Enums\UploadContext;

/**
 * Every app_settings key the platform reads, with its value shape (Slice 20, D307). A key here that is not stored yet
 * is shown as "not configured". Later slices append their keys to the S-blocks of DEFINITIONS (append-only).
 *
 * Each entry: [kind, unit|null, decision ref, fixed].
 */
final class SettingCatalog
{
    /** @var array<string, array{0: SettingKind, 1: ?string, 2: ?string, 3?: bool}> */
    private const DEFINITIONS = [
        // Slice 1 — OTP (D3: 4 digits, decided in requirements → fixed).
        'otp.length' => [SettingKind::Integer, 'digits', 'D3', true],
        'otp.expiry_seconds' => [SettingKind::Integer, 'seconds', null],
        'otp.max_attempts' => [SettingKind::Integer, 'attempts', null],
        'otp.resend_cooldown_seconds' => [SettingKind::Integer, 'seconds', null],
        // Slice 5b — offer validity options (D5 → fixed).
        'offers.validity_days' => [SettingKind::IntegerList, 'days', 'D5', true],
        // Slice 4 / SFIX.
        'requests.max_attachments' => [SettingKind::Integer, 'files', 'D53'],
        // Slices 5c / 6.
        'bookings.buffer_minutes' => [SettingKind::Integer, 'minutes', 'D108'],
        'bookings.start_early_minutes' => [SettingKind::Integer, 'minutes', 'D126'],
        // Slices 12b / 17.
        'support.auto_close_days' => [SettingKind::Integer, 'days', 'D160'],
        'support.chat_access_minutes' => [SettingKind::Integer, 'minutes', 'S17'],
        // Slice 10 (S10).
        'payments.booking_payment_required' => [SettingKind::Boolean, null, 'D185'],
        'payments.intent_ttl_minutes' => [SettingKind::Integer, 'minutes', 'D191'],
        // Slice 9b (S9b).
        'store.shipping_fee' => [SettingKind::Money, 'sar', 'D201'],
        'store.max_qty_per_line' => [SettingKind::Integer, 'items', 'D208'],
        'tax.vat_percent' => [SettingKind::Decimal, 'percent', 'D202'],
        // Slice 11 (S11).
        'payouts.commission_percent' => [SettingKind::Decimal, 'percent', 'D215'],
        'payouts.hold_days' => [SettingKind::Integer, 'days', 'D215'],
        'payouts.min_amount' => [SettingKind::Money, 'sar', 'D216'],
        // Slice 19 (S19).
        'finance.adjustment_max_amount' => [SettingKind::Money, 'sar', 'D288'],
        'finance.adjustment_approval_amount' => [SettingKind::Money, 'sar', 'D288'],
        // Slice 13 (S13).
        'uploads.team_photo' => [SettingKind::UploadLimits, null, 'D235'],
        // --- later slices: append above this line in a marked block ---
    ];

    /**
     * @return array<string, array{kind: SettingKind, unit: ?string, decision: ?string, fixed: bool}>
     */
    public static function all(): array
    {
        $out = [];
        foreach (self::DEFINITIONS as $key => $def) {
            $out[$key] = ['kind' => $def[0], 'unit' => $def[1], 'decision' => $def[2], 'fixed' => $def[3] ?? false];
        }
        foreach (UploadContext::cases() as $context) {
            $out[$context->settingKey()] ??= ['kind' => SettingKind::UploadLimits, 'unit' => null, 'decision' => 'Q14', 'fixed' => false];
        }
        ksort($out);

        return $out;
    }

    /**
     * Unknown keys (stored by a slice that did not register here) are plain JSON values.
     *
     * @return array{kind: SettingKind, unit: ?string, decision: ?string, fixed: bool}
     */
    public static function definition(string $key): array
    {
        return self::all()[$key] ?? ['kind' => SettingKind::Json, 'unit' => null, 'decision' => null, 'fixed' => false];
    }

    public static function group(string $key): string
    {
        return strtok($key, '.') ?: $key;
    }
}
