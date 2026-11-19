<?php

namespace Database\Seeders;

use App\Enums\UploadContext;

/**
 * Initial values for settings the platform cannot run without (owner instruction 2026-11-17: "حط قيم مبدأيه والادمن
 * يقدر يغيرها في اي وقت من الاعدادات"). Used by AppSettingSeeder for new keys and by the 2026_12_05 migration to fill
 * keys an earlier deploy left empty. The admin changes any of them in Settings.
 */
final class InitialSettings
{
    public const OTP = [
        'otp.expiry_seconds' => 300,
        'otp.max_attempts' => 5,
        'otp.resend_cooldown_seconds' => 60,
    ];

    /** @return array<string, array{mimes: list<string>, max_kb: int}|int> */
    public static function uploads(): array
    {
        $image = ['mimes' => ['jpg', 'jpeg', 'png', 'webp', 'heic'], 'max_kb' => 10240];
        $limits = [
            UploadContext::ProviderDocument->value => ['mimes' => ['pdf', 'jpg', 'jpeg', 'png'], 'max_kb' => 10240],
            UploadContext::Portfolio->value => $image,
            UploadContext::ChatImage->value => $image,
            UploadContext::ChatFile->value => ['mimes' => ['pdf', 'doc', 'docx'], 'max_kb' => 10240],
            UploadContext::ChatVoice->value => ['mimes' => ['m4a', 'aac', 'mp3', 'ogg', 'wav'], 'max_kb' => 10240],
            UploadContext::BookingUpdate->value => $image,
            UploadContext::CatalogImage->value => $image,
            UploadContext::Banner->value => $image,
            UploadContext::Avatar->value => $image,
            UploadContext::RequestAttachment->value => ['mimes' => ['jpg', 'jpeg', 'png', 'heic', 'pdf'], 'max_kb' => 10240], // D242
            UploadContext::TeamPhoto->value => $image,
            UploadContext::StoreReturnPhoto->value => $image, // D342
            UploadContext::OnboardingImage->value => $image,
        ];

        $out = [];
        foreach (UploadContext::cases() as $context) {
            $out[$context->settingKey()] = $limits[$context->value];
        }
        $out['requests.max_attachments'] = 5; // D242
        $out['requests.max_distance_from_city_km'] = 60; // QA BUG-006

        return $out;
    }

    /** @return array<string, mixed> */
    public static function all(): array
    {
        return [...self::OTP, ...self::uploads()];
    }
}
